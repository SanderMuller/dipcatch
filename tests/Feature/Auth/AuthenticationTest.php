<?php declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function (): void {
    $response = $this->get(route('login'));

    $response
        ->assertOk()
        ->assertSee('Sign in with a passkey')
        ->assertSee('Or continue with email')
        ->assertSee("options: '" . route('passkey.login-options') . "'", false)
        ->assertSee("submit: '" . route('passkey.login') . "'", false)
        ->assertDontSee(route('passkey.confirm-options'), false);
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/app');

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('login screen omits passkeys when the feature is disabled', function (): void {
    config()->set('fortify.features', [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication(),
    ]);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign in with a passkey');
});
