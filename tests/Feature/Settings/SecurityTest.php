<?php declare(strict_types=1);

use App\Livewire\Settings\Security;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Two-factor authentication')
        ->assertSee('Enable 2FA')
        ->assertSee('Passkeys')
        ->assertSee('No passkeys yet');
});

test('security settings page requires password confirmation when enabled', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('security settings page renders without two factor when feature is disabled', function (): void {
    config()->set('fortify.features', []);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Two-factor authentication')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password');
});

test('two factor authentication disabled when confirmation abandoned between requests', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test(Security::class);

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('manual setup key contains the unencrypted two factor secret', function (): void {
    $user = User::factory()->create();

    mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('generateSecretKey')
        ->once()
        ->andReturn('CMN5TSOG355MJ55R')
        ->shouldReceive('qrCodeUrl')
        ->once()
        ->with(config('app.name'), $user->email, 'CMN5TSOG355MJ55R')
        ->andReturn('otpauth://totp/Dipcatch:test@example.com?secret=CMN5TSOG355MJ55R');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('enable')
        ->assertSet('manualSetupKey', 'CMN5TSOG355MJ55R');
});

test('invalid two factor secret type reports a setup error', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt(['invalid-secret']),
        'two_factor_confirmed_at' => now(),
    ])->save();

    mock(TwoFactorAuthenticationProvider::class)
        ->shouldNotReceive('qrCodeUrl');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('enable')
        ->assertSet('manualSetupKey', '')
        ->assertHasErrors('setupData');
});

test('password can be updated', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('security settings page lists only the authenticated users passkeys', function (): void {
    $user = User::factory()->create();
    createUserPasskey($user, 'MacBook Pro');
    createUserPasskey(User::factory()->create(), 'Other laptop');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('MacBook Pro')
        ->assertDontSee('Other laptop')
        ->assertDontSee('No passkeys yet');
});

test('security settings page escapes passkey names', function (): void {
    $user = User::factory()->create();
    createUserPasskey($user, "<script>alert('xss')</script>");

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))->assertOk()->assertDontSeeHtml("<script>alert('xss')</script>")->assertSeeHtml('&lt;script&gt;');
});

test('passkeys are loaded newest first for the security page', function (): void {
    $user = User::factory()->create();
    $older = createUserPasskey($user, 'Old key');
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = createUserPasskey($user, 'New key');

    $this->actingAs($user);

    $passkeys = Livewire::test(Security::class)->get('passkeys');

    expect($passkeys)->toHaveCount(2)
        ->and($passkeys[0]['id'])->toBe($newer->id)
        ->and($passkeys[0]['name'])->toBe('New key')
        ->and($passkeys[1]['id'])->toBe($older->id);
});

test('removing a passkey leaves the users other passkeys in place', function (): void {
    $user = User::factory()->create();
    $keep = createUserPasskey($user, 'Keep this key');
    $remove = createUserPasskey($user, 'MacBook Pro');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('confirmDelete', $remove->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingPasskeyName', 'MacBook Pro')
        ->call('deletePasskey')
        ->assertSet('showDeleteModal', false);

    $this->assertModelMissing($remove);
    $this->assertModelExists($keep);

    $passkeys = Livewire::test(Security::class)->get('passkeys');
    expect($passkeys)->toHaveCount(1)
        ->and($passkeys[0]['id'])->toBe($keep->id);
});

test('deleting a passkey does nothing when no passkey is selected', function (): void {
    $user = User::factory()->create();
    $passkey = createUserPasskey($user, 'MacBook Pro');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('deletePasskey')
        ->assertSet('passkeys.0.id', $passkey->id);

    $this->assertModelExists($passkey);
});

test('closing the delete modal does not remove the passkey', function (): void {
    $user = User::factory()->create();
    $passkey = createUserPasskey($user, 'MacBook Pro');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('confirmDelete', $passkey->id)
        ->call('closeDeleteModal')
        ->call('deletePasskey')
        ->assertSet('showDeleteModal', false);

    $this->assertModelExists($passkey);
});

test('a user cannot open the delete modal for another users passkey', function (): void {
    $user = User::factory()->create();
    $otherPasskey = createUserPasskey(User::factory()->create(), 'Other key');

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->call('confirmDelete', $otherPasskey->id);
})->throws(ModelNotFoundException::class);

test('passkey well known endpoints advertise the security page', function (): void {
    $this->get('/.well-known/passkey-endpoints')
        ->assertOk()
        ->assertJson([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
});

function createUserPasskey(User $user, string $name): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => Str::random(40),
        'credential' => [],
    ]);
}

/**
 * Drives the whole enable flow without a real authenticator app.
 */
function fakeTotpProvider(string $secret = 'CMN5TSOG355MJ55R', bool $codeIsValid = true): void
{
    mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('generateSecretKey')->andReturn($secret)
        ->shouldReceive('qrCodeUrl')->andReturn('otpauth://totp/Dipcatch?secret=' . $secret)
        ->shouldReceive('verify')->andReturn($codeIsValid);
}

test('confirming a valid code turns two factor on and closes the modal', function (): void {
    fakeTotpProvider();
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->call('enable')
        ->assertSet('showModal', true)
        // The QR step comes first; the code field only renders after this.
        ->call('showVerificationIfNecessary')
        ->assertSet('showVerificationStep', true)
        ->set('code', '123456')
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSet('twoFactorEnabled', true)
        ->assertSet('showModal', false)
        // The rendered branch, not just the value behind it. `assertSet`
        // resolves the computed without ever looking at the markup, so an
        // inverted `@if` on that line would pass every other assertion here.
        ->assertSee('Disable 2FA')
        ->assertDontSee('Enable 2FA')
        // The recovery-codes child renders inside that branch, which is what
        // covers dropping the prop it never declared.
        ->assertSee('Backup codes');
});

test('a wrong code leaves two factor off and says so on the page', function (): void {
    // The audit carried a claim that this message is swallowed because
    // Fortify throws into a named bag. It is not: Livewire flattens the bag
    // into `default` and the field renders it. Nothing asserted that.
    fakeTotpProvider(codeIsValid: false);
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->call('enable')
        ->call('showVerificationIfNecessary')
        ->set('code', '000000')
        ->call('confirmTwoFactor')
        ->assertSet('twoFactorEnabled', false)
        // Still on the verification step, so the field that carries the
        // error is on the page to carry it.
        ->assertSet('showVerificationStep', true)
        ->assertSee('The provided two factor authentication code was invalid.');
});

test('disabling two factor turns the flag off and clears the secret', function (): void {
    // Arranged straight on the columns, the way the abandoned-confirmation
    // test above does it: this is about `disable()`, not about the enable
    // flow that precedes it.
    $user = User::factory()->create()->forceFill([
        'two_factor_secret' => encrypt('CMN5TSOG355MJ55R'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->save();

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->assertSet('twoFactorEnabled', true)
        ->call('disable')
        ->assertSet('twoFactorEnabled', false)
        ->assertSee('Enable 2FA');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
});

test('closing the modal without confirming leaves two factor off', function (): void {
    fakeTotpProvider();
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->call('enable')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false)
        ->assertSet('code', '')
        ->assertSet('manualSetupKey', '');
});

test('without confirmation mode enabling turns two factor on immediately', function (): void {
    // The path `beforeEach` hides. Fortify counts a secret as enabled here
    // without waiting for a confirmation, so this is the only mode where
    // enabling and closing the modal change the answer at all.
    Features::twoFactorAuthentication([
        'confirm' => false,
        'confirmPassword' => true,
    ]);

    fakeTotpProvider();
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->assertSet('requiresConfirmation', false)
        ->call('enable')
        ->assertSet('twoFactorEnabled', true)
        ->call('closeModal')
        ->assertSet('twoFactorEnabled', true);
});
