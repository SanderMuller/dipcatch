<?php declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function (): void {
    $response = $this->get(route('login'));

    $response
        ->assertOk()
        ->assertSee('Sign in with a passkey')
        ->assertSee("options: '" . route('passkey.login-options') . "'", false)
        ->assertSee("submit: '" . route('passkey.login') . "'", false)
        ->assertDontSee(route('passkey.confirm-options'), false);
});

test('login screen leads with social login and keeps the passkey below the form', function (): void {
    configureSocialProviders();

    $body = (string) $this->get(route('login'))->assertOk()->getContent();

    $positionOf = function (string $needle) use ($body): int {
        $at = strpos($body, $needle);

        expect($at)->not->toBeFalse("The login page does not contain [{$needle}].");
        assert(is_int($at));

        return $at;
    };

    // Order on the page is the change: providers, then email and password,
    // then the passkey. A plain `assertSee` per string would still pass with
    // the passkey back on top.
    $google = $positionOf(route('social.redirect', 'google'));
    $apple = $positionOf(route('social.redirect', 'apple'));
    $form = $positionOf(route('login.store'));
    $passkey = $positionOf('data-test="passkey-login-link"');

    expect($google)->toBeLessThan($form)
        ->and($apple)->toBeLessThan($form)
        ->and($form)->toBeLessThan($passkey);
});

test('login screen hides a provider that is not fully set up', function (): void {
    configureSocialProviders();

    // Apple keeps its client id but loses the route to a client secret, which
    // is the state a deployer lands in halfway through the Apple setup.
    config()->set('services.apple', [
        'client_id' => 'apple-client-id',
        'redirect' => 'https://dipcatch.test/auth/apple/callback',
    ]);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('social.redirect', 'google'), false)
        ->assertDontSee(route('social.redirect', 'apple'), false)
        // The header names what is on the page. It must not promise Apple
        // when the button for it is gone.
        ->assertSee('Continue with Google, or use your email and password')
        ->assertDontSee('Continue with Google or Apple');
});

test('register screen offers the same providers, with its own separator', function (): void {
    configureSocialProviders();

    $this->get(route('register'))
        ->assertOk()
        ->assertSee(route('social.redirect', 'google'), false)
        ->assertSee(route('social.redirect', 'apple'), false)
        // The register page passes its own wording; the login page's would be
        // wrong here and nothing else would catch the prop being dropped.
        ->assertSee('or sign up with email')
        ->assertDontSee('or with email');
});

test('login and register screens show no social login until the env values are set', function (): void {
    config()->set('services.google', []);
    config()->set('services.apple', []);

    foreach ([route('login'), route('register')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertDontSee('Continue with')
            ->assertDontSee('/auth/google/redirect', false)
            ->assertDontSee('/auth/apple/redirect', false)
            // The separator belongs to the provider block and goes with it,
            // so an empty block leaves no stray divider behind.
            ->assertDontSee('or with email')
            ->assertDontSee('or sign up with email');
    }

    $this->get(route('login'))->assertSee('Enter your email and password below to log in');
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
