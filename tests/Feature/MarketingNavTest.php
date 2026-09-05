<?php declare(strict_types=1);

use App\Models\User;

it('offers a guest both a way in and a way to join', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertSee('Sign in');
});

it('offers a signed-in visitor the app instead of a sign-in link', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('Open app')
        ->assertDontSee('Sign in');
});
