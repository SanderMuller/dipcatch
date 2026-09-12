<?php declare(strict_types=1);

use App\Livewire\Settings\TwoFactor\RecoveryCodes;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('recovery codes contain the unencrypted stored values', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('CMN5TSOG355MJ55R'),
        'two_factor_recovery_codes' => encrypt(json_encode(['first-code', 'second-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);

    Livewire::test(RecoveryCodes::class)
        ->assertSet('recoveryCodes', ['first-code', 'second-code']);
});

test('invalid recovery code type reports an error', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('CMN5TSOG355MJ55R'),
        'two_factor_recovery_codes' => encrypt(['invalid-code']),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);

    Livewire::test(RecoveryCodes::class)
        ->assertSet('recoveryCodes', [])
        ->assertHasErrors('recoveryCodes');
});
