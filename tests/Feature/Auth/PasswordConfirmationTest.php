<?php declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Features;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response
        ->assertOk()
        ->assertSee('Confirm with passkey')
        ->assertSee('Or confirm with password')
        ->assertSee("options: '" . route('passkey.confirm-options') . "'", false)
        ->assertSee("submit: '" . route('passkey.confirm') . "'", false)
        ->assertDontSee(route('passkey.login-options'), false);
});

test('confirm password screen omits passkeys when the feature is disabled', function (): void {
    config()->set('fortify.features', [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication(),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertDontSee('Confirm with passkey');
});
