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
        ->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee("<script>alert('xss')</script>", false)
        ->assertSee('&lt;script&gt;', false);
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
