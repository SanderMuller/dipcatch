<?php declare(strict_types=1);

use App\Models\User;

test('guests see the homepage with the register CTA', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Same product, every shop, one alert.', escape: false);
    $response->assertSee('Create a free account');
    $response->assertSee(route('register'));
    $response->assertSee(route('login'));
});

test('authenticated users see the dashboard CTA instead of the register CTA', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Open dashboard');
    $response->assertDontSee('Create a free account');
});
