<?php declare(strict_types=1);

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;
use SocialiteProviders\Apple\Provider as AppleProvider;

beforeEach(function (): void {
    // The `social-login` bucket lives in a real Redis server and survives
    // between runs, so without this the file trips its own 20/min budget and
    // every request after the twentieth comes back 429.
    clearRedisRateLimiter('social-login');

    configureSocialProviders();
});

/**
 * Stand in for the provider round trip. Everything past `->user()` is the
 * app's own code, and that is what these tests cover.
 *
 * @param  array<string, mixed>  $raw
 */
function fakeSocialiteUser(string $id, ?string $email, ?string $name, array $raw = []): SocialiteUser
{
    $user = new SocialiteUser();

    $user->map([
        'id' => $id,
        'email' => $email,
        'name' => $name,
    ]);

    $user->setRaw($raw);

    return $user;
}

function fakeSocialiteDriver(SocialiteUser $user, string $driver = 'google'): void
{
    $class = $driver === 'apple' ? AppleProvider::class : GoogleProvider::class;

    Socialite::shouldReceive('driver')
        ->with($driver)
        ->andReturn(Mockery::mock($class, function (MockInterface $mock) use ($user, $driver): void {
            if ($driver === 'apple') {
                // `once()`, not a bare expectation: Apple's cross-site POST
                // has no `state` to check, so dropping either call from the
                // controller breaks production login while an expectation
                // with no cardinality stays satisfied by zero calls.
                $mock->shouldReceive('stateless')->once()->andReturnSelf();
                $mock->shouldReceive('cookieNonce')->once()->andReturnSelf();
            } else {
                // Google runs the standard stateful flow; the `state` check is
                // what protects it, so it must not be built stateless.
                $mock->shouldNotReceive('stateless');
            }

            $mock->shouldReceive('user')->andReturn($user);
        }));
}

test('a first-time Google user gets an account that needs no email verification', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-1', 'nieuw@example.test', 'Nieuwe Gebruiker', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $user = User::query()->where('email', 'nieuw@example.test')->sole();

    expect($user->name)->toBe('Nieuwe Gebruiker')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->socialAccounts()->where('provider', SocialProvider::Google)->value('provider_id'))
        ->toBe('google-sub-1');

    $this->assertAuthenticatedAs($user);
});

test('a provider that did not verify the address still gets the user a verification mail', function (): void {
    Notification::fake();

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-7', 'onbevestigd@example.test', 'Onbevestigd', [
        'email_verified' => false,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $user = User::query()->where('email', 'onbevestigd@example.test')->sole();

    // Logged in but unverified, so `EnsureEmailIsVerified` holds them at the
    // notice page. Without the `Registered` event the mail that clears it is
    // never sent and the account is stuck.
    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a provider that verified the address sends no verification mail', function (): void {
    Notification::fake();

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-8', 'bevestigd@example.test', 'Bevestigd', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    Notification::assertNotSentTo(User::query()->sole(), VerifyEmail::class);
});

test('a returning user is matched on the provider account, not the email address', function (): void {
    $user = User::factory()->create(['email' => 'oud@example.test']);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
        'provider_id' => 'google-sub-2',
    ]);

    // The address on the Google account changed since the link was made.
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-2', 'nieuw-adres@example.test', 'Oude Gebruiker', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->email)->toBe('oud@example.test');
});

test('a verified provider address links to the account that already owns it', function (): void {
    $user = User::factory()->create(['email' => 'bestaand@example.test']);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-3', 'bestaand@example.test', 'Bestaande Gebruiker', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);

    expect(User::query()->count())->toBe(1)
        ->and($user->socialAccounts()->count())->toBe(1);
});

test('an unverified provider address cannot take over an existing account', function (): void {
    $user = User::factory()->create(['email' => 'slachtoffer@example.test']);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-4', 'slachtoffer@example.test', 'Aanvaller', [
        'email_verified' => false,
    ]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        // The message, not just the key: the four failures are otherwise
        // interchangeable and the user gets the wrong instruction.
        ->assertSessionHasErrors(['email' => __(
            'An account already uses this email address, and :provider has not confirmed the address belongs to you. Log in with your password instead.',
            ['provider' => 'Google'],
        )]);

    $this->assertGuest();

    expect($user->socialAccounts()->count())->toBe(0);
});

test('claiming an unverified local account kills the password that was set on it', function (): void {
    // Account pre-hijacking: anyone can register an address they do not own,
    // because nothing proves ownership until the address is verified. When the
    // provider later proves the real owner is signing in, the squatter's
    // password must stop working.
    $squatted = User::factory()->unverified()->create(['email' => 'slachtoffer@example.test']);
    $originalHash = $squatted->password;
    $originalRecaller = $squatted->remember_token;

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-9', 'slachtoffer@example.test', 'Echte Eigenaar', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $squatted->refresh();

    expect($squatted->password)->not->toBe($originalHash)
        ->and($squatted->hasVerifiedEmail())->toBeTrue()
        // Cleared during the claim, then reissued by the login that follows —
        // so the assertion is that the squatter's recaller cookie is dead, not
        // that the column is empty.
        ->and($squatted->remember_token)->not->toBe($originalRecaller);

    // The password the squatter chose no longer opens the account.
    $this->post(route('logout'));
    $this->post(route('login.store'), [
        'email' => 'slachtoffer@example.test',
        'password' => 'password',
    ])->assertSessionHasErrorsIn('email');
});

test('claiming an unverified local account revokes its passkeys, two factor and live sessions', function (): void {
    // The password alone is not the whole credential set. Fortify's passkey
    // and two-factor routes sit behind `auth` and `password.confirm` but not
    // `verified`, so a squatter can set both up before the real owner arrives.
    $squatted = User::factory()->unverified()->withTwoFactor()->create([
        'email' => 'kraker@example.test',
    ]);

    $squatted->passkeys()->create([
        'name' => 'Squatter key',
        'credential_id' => 'squatter-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);

    DB::table('sessions')->insert([
        'id' => 'squatter-session',
        'user_id' => $squatted->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'squatter',
        'payload' => 'x',
        'last_activity' => Carbon::now()->getTimestamp(),
    ]);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-15', 'kraker@example.test', 'Echte Eigenaar', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $squatted->refresh();

    expect($squatted->passkeys()->count())->toBe(0)
        ->and($squatted->two_factor_secret)->toBeNull()
        ->and($squatted->two_factor_confirmed_at)->toBeNull()
        ->and($squatted->two_factor_recovery_codes)->toBeNull()
        ->and(DB::table('sessions')->where('id', 'squatter-session')->exists())->toBeFalse();
});

test('two accounts differing only in case are refused rather than one picked at random', function (): void {
    // Reachable through an invitation, which stores the address as the admin
    // typed it. Picking either row would reset a password on an account that
    // may not belong to the person signing in.
    User::factory()->create(['email' => 'dubbel@example.test']);
    User::factory()->create(['email' => 'Dubbel@Example.test']);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-16', 'dubbel@example.test', 'Dubbel', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('claiming a verified local account leaves its password alone', function (): void {
    // The mirror of the test above. A verified account proved ownership of the
    // mailbox already, so its password is trusted and must survive.
    $user = User::factory()->create(['email' => 'bevestigd-al@example.test']);
    $originalHash = $user->password;

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-10', 'bevestigd-al@example.test', 'Bestaand', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    expect($user->fresh()->password)->toBe($originalHash);
});

test('a second account at a provider the user already linked is refused, not a 500', function (): void {
    $user = User::factory()->create(['email' => 'twee-google@example.test']);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
        'provider_id' => 'google-sub-first',
    ]);

    // A different Google account whose verified address matches the same user.
    // The unique index on (user_id, provider) rejects the insert; the flow has
    // to turn that into a message rather than an unhandled QueryException.
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-second', 'twee-google@example.test', 'Tweede', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    expect($user->socialAccounts()->count())->toBe(1);
});

test('an address that differs only in case matches the account that already owns it', function (): void {
    // `users.email` is byte-unique on Postgres and registration stores the
    // address as typed, so a case-sensitive match would silently hand the user
    // a second, empty account instead of the one holding their products.
    $user = User::factory()->create(['email' => 'Sander@Example.test']);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-11', 'sander@example.test', 'Sander', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);

    expect(User::query()->count())->toBe(1)
        ->and($user->socialAccounts()->count())->toBe(1);
});

test('a returning user whose provider sends no address this time still gets in', function (): void {
    // Apple omits the email claim on later sign-ins. The link is keyed on the
    // subject claim, so the lookup must run before the missing-email refusal.
    $user = User::factory()->create(['email' => 'terug@example.test']);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Apple,
        'provider_id' => 'apple-sub-returning',
    ]);

    fakeSocialiteDriver(fakeSocialiteUser('apple-sub-returning', null, null), 'apple');

    $this->post(route('social.callback.post', 'apple'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
});

test('a social login is remembered, so the user is not sent back through the provider each session', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-12', 'onthouden@example.test', 'Onthouden', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect('/app')
        ->assertCookie(Auth::guard()->getRecallerName());
});

test('a first-time account takes its currency from the browser language', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-13', 'brit@example.test', 'Brit', [
        'email_verified' => true,
    ]));

    $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
        ->get(route('social.callback', 'google'))
        ->assertRedirect('/app');

    expect(User::query()->sole()->default_currency)->toBe('GBP');
});

test('a provider name longer than the column is truncated rather than failing the insert', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-14', 'lang@example.test', str_repeat('a', 300), [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    expect(User::query()->sole()->name)->toHaveLength(255);
});

test('an empty subject claim is refused rather than linked to an empty provider id', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('   ', 'leeg@example.test', 'Leeg', ['email_verified' => true]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0);
});

test('a configured provider sends the user to that provider', function (): void {
    // The only test that drives the redirect end with real Socialite; every
    // other one asserts a 404 or the guest bounce.
    $response = $this->get(route('social.redirect', 'google'));

    $location = (string) $response->headers->get('Location');

    expect($response->getStatusCode())->toBe(302)
        ->and($location)->toStartWith('https://accounts.google.com/')
        ->and($location)->toContain('client_id=google-client-id');
});

test('a provider that shares no email address cannot create an account', function (): void {
    fakeSocialiteDriver(fakeSocialiteUser('google-sub-5', null, 'Geen Adres'));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(User::query()->count())->toBe(0);
});

test('a user with two factor enabled still gets the challenge after a social login', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => 'tweefactor@example.test']);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
        'provider_id' => 'google-sub-6',
    ]);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-6', 'tweefactor@example.test', 'Twee Factor', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id)
        // Fortify reads this to decide the session length. Dropping it would
        // silently shorten the session for exactly the users with 2FA on.
        ->assertSessionHas('login.remember', true);

    $this->assertGuest();
});

test('two factor that was started but never confirmed does not hold the user at the challenge', function (): void {
    $user = User::factory()->create([
        'email' => 'halve-tweefactor@example.test',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => null,
    ]);

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
        'provider_id' => 'google-sub-halffactor',
    ]);

    fakeSocialiteDriver(fakeSocialiteUser('google-sub-halffactor', 'halve-tweefactor@example.test', 'Halve', [
        'email_verified' => true,
    ]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
});

test('Apple sends no name after the first sign-in, so the email local part is used', function (): void {
    fakeSocialiteDriver(
        fakeSocialiteUser('apple-sub-1', 'anoniem@privaterelay.appleid.test', null, ['email_verified' => 'true']),
        'apple',
    );

    $this->post(route('social.callback.post', 'apple'))->assertRedirect('/app');

    expect(User::query()->sole()->name)->toBe('anoniem');
});

test('Apple posts its callback cross-site, so that route takes no CSRF token', function (): void {
    // Asserted on the route rather than through a request: the CSRF middleware
    // short-circuits under `runningUnitTests()`, so a POST without a token
    // passes in the suite whether the exclusion is there or not.
    expect(Route::getRoutes()->getByName('social.callback.post')?->excludedMiddleware() ?? [])
        ->toContain(PreventRequestForgery::class);

    // The GET callback does not exclude it, so the hole is Apple's POST only.
    expect(Route::getRoutes()->getByName('social.callback')?->excludedMiddleware() ?? [])
        ->not->toContain(PreventRequestForgery::class);
});

test('a provider outside the allow list is not routable', function (): void {
    $this->get('/auth/facebook/redirect')->assertNotFound();
    $this->post('/auth/google/callback')->assertMethodNotAllowed();
});

test('a provider without credentials is a 404 rather than a broken redirect', function (): void {
    config()->set('services.google', []);
    config()->set('services.apple', []);

    $this->get(route('social.redirect', 'google'))->assertNotFound();
    $this->get(route('social.redirect', 'apple'))->assertNotFound();
    $this->get(route('social.callback', 'google'))->assertNotFound();
});

test('a half-configured provider stays off, so nobody is sent out to an error page', function (): void {
    // A client id alone used to be enough to show the button. It is not
    // enough to complete the flow, so it is no longer enough to show it.
    config()->set('services.google', [
        'client_id' => 'google-client-id',
        'redirect' => 'https://dipcatch.test/auth/google/callback',
    ]);

    expect(SocialProvider::Google->isConfigured())->toBeFalse();

    $this->get(route('social.redirect', 'google'))->assertNotFound();
});

test('Apple counts as configured through the key trio as well as a ready-made secret', function (): void {
    $base = [
        'client_id' => 'apple-client-id',
        'redirect' => 'https://dipcatch.test/auth/apple/callback',
    ];

    // Apple mints the secret from the .p8 key, so the trio stands in for it.
    config()->set('services.apple', [...$base,
        'key_id' => 'apple-key-id',
        'team_id' => 'apple-team-id',
        'private_key' => '/tmp/AuthKey_TEST.p8',
    ]);
    expect(SocialProvider::Apple->isConfigured())->toBeTrue();

    config()->set('services.apple', [...$base, 'client_secret' => 'apple-client-secret']);
    expect(SocialProvider::Apple->isConfigured())->toBeTrue();

    // Neither route to a secret is complete: the id and redirect alone are not
    // enough, and two thirds of the trio are not either.
    config()->set('services.apple', $base);
    expect(SocialProvider::Apple->isConfigured())->toBeFalse();

    config()->set('services.apple', [...$base, 'key_id' => 'apple-key-id', 'team_id' => 'apple-team-id']);
    expect(SocialProvider::Apple->isConfigured())->toBeFalse();
});

test('a credential set to an empty or blank string counts as absent', function (): void {
    // An unset variable in `.env` reaches config as `''`, not null.
    config()->set('services.google.client_secret', '');
    expect(SocialProvider::Google->isConfigured())->toBeFalse();

    config()->set('services.google.client_secret', '   ');
    expect(SocialProvider::Google->isConfigured())->toBeFalse();
});

test('a signed-in user is kept out of the social login flow', function (): void {
    // A bare `assertRedirect()` asserts only a 3xx, which the real Socialite
    // redirect to Google also satisfies — so it would pass with the `guest`
    // middleware removed. Pin the target.
    $this->actingAs(User::factory()->create())
        ->get(route('social.redirect', 'google'))
        ->assertRedirect(route('home'));
});

test('a failing provider callback sends the user back to login with a message', function (): void {
    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock(GoogleProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('user')->andThrow(new InvalidStateException());
        }));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
