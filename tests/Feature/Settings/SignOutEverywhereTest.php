<?php declare(strict_types=1);

use App\Actions\Users\SignOutEverywhere;
use App\Livewire\Settings\SignOutEverywhereForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

function giveOAuthAccess(User $user): void
{
    DB::table('oauth_access_tokens')->insert([
        'id' => 'token-' . $user->id,
        'user_id' => $user->id,
        'client_id' => (string) Str::uuid(),
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => 'refresh-' . $user->id,
        'access_token_id' => 'token-' . $user->id,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);
}

test('the security page offers to sign out everywhere', function (): void {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSeeLivewire(SignOutEverywhereForm::class);
});

test('signing out everywhere ends other sessions and every connected app, and keeps this one', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $bystander = User::factory()->create();
    giveOAuthAccess($user);
    giveOAuthAccess($bystander);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'pending', 'created_at' => now()]);
    $user->passkeys()->create([
        'name' => 'Own key',
        'credential_id' => 'own-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);
    $oldHash = $user->password;

    $this->actingAs($user);

    Livewire::test(SignOutEverywhereForm::class)
        ->set('password', 'password')
        ->call('signOutEverywhere')
        ->assertHasNoErrors()
        ->assertSet('showConfirm', false);

    $user->refresh();

    expect(DB::table('oauth_access_tokens')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('oauth_refresh_tokens')->where('id', 'refresh-' . $user->id)->exists())->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $bystander->id)->exists())->toBeTrue()
        // A pending reset link is a way in too.
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse()
        // A new hash of the same password: other sessions stop matching it.
        ->and($user->password)->not->toBe($oldHash)
        ->and(Hash::check('password', $user->password))->toBeTrue()
        // Ways in the person set up themselves stay.
        ->and($user->passkeys()->exists())->toBeTrue()
        ->and($user->two_factor_confirmed_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('another browser is signed out on its next request', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->get('/app')->assertOk();

    // The same account, signing out everywhere from a different browser.
    auth()->forgetGuards();
    auth()->setUser($user->fresh() ?? $user);
    app(SignOutEverywhere::class)('password');
    auth()->forgetGuards();

    $this->get('/app')->assertRedirect(route('login'));
});

test('a wrong password signs out nothing', function (): void {
    $user = User::factory()->create();
    giveOAuthAccess($user);

    $this->actingAs($user);

    Livewire::test(SignOutEverywhereForm::class)
        ->set('password', 'not-my-password')
        ->call('signOutEverywhere')
        ->assertHasErrors(['password' => 'current_password'])
        ->assertSet('password', '');

    expect(DB::table('oauth_access_tokens')->where('user_id', $user->id)->exists())->toBeTrue();
});
