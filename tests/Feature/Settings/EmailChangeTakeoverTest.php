<?php declare(strict_types=1);

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('a session alone cannot move the address and then claim the account through a reset', function (): void {
    // Security audit lead 1: someone holding only a session cookie, without the
    // password, changes the address to one they read, then resets the password
    // there. The claim path treats the now-unverified account as never owned.
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $owner->passkeys()->create([
        'name' => 'Owner key',
        'credential_id' => 'owner-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);

    $this->actingAs($owner);

    Livewire::test(Profile::class)
        ->set('email', 'intruder@example.test')
        ->call('updateProfileInformation');

    auth()->logout();

    $this->post(route('password.update'), [
        'token' => Password::createToken($owner->fresh() ?? $owner),
        'email' => 'intruder@example.test',
        'password' => 'intruder-password',
        'password_confirmation' => 'intruder-password',
    ]);

    $owner->refresh();

    expect($owner->two_factor_confirmed_at)->not->toBeNull()
        ->and($owner->passkeys()->exists())->toBeTrue()
        ->and(Hash::check('intruder-password', $owner->password))->toBeFalse();
});
