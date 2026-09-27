<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification): true {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

function resetPasswordTo(User $user, string $password): TestResponse
{
    return test()->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('a reset on an unverified account takes it from whoever squatted it', function (): void {
    // The reset link reached the mailbox, which proves ownership the account
    // never had. Whatever the squatter set up must stop working.
    $squatted = User::factory()->unverified()->withTwoFactor()->create();
    $squatted->passkeys()->create([
        'name' => 'Squatter key',
        'credential_id' => 'squatter-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);
    DB::table('oauth_access_tokens')->insert([
        'id' => 'squatter-token',
        'user_id' => $squatted->id,
        'client_id' => (string) Str::uuid(),
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $squatted->updatePushSubscription('https://push.example.test/squatter', 'key', 'token');

    resetPasswordTo($squatted, 'the-owners-password')->assertSessionHasNoErrors();

    $squatted->refresh();

    expect($squatted->hasVerifiedEmail())->toBeTrue()
        ->and($squatted->two_factor_secret)->toBeNull()
        ->and($squatted->two_factor_confirmed_at)->toBeNull()
        ->and($squatted->passkeys()->exists())->toBeFalse()
        // Otherwise the squatter's browser keeps receiving the owner's alerts.
        ->and($squatted->pushSubscriptions()->exists())->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $squatted->id)->exists())->toBeFalse();
});

test('a reset on a verified account keeps its passkeys and two factor', function (): void {
    // Removing them would let anyone who reads the mailbox past the second factor.
    $user = User::factory()->withTwoFactor()->create();
    $user->passkeys()->create([
        'name' => 'Own key',
        'credential_id' => 'own-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);

    resetPasswordTo($user, 'a-new-password')->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->passkeys()->exists())->toBeTrue()
        ->and(Hash::check('a-new-password', $user->password))->toBeTrue();
});
