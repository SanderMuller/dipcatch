<?php declare(strict_types=1);

use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function (): void {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});
