<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;

it('offers a guest both a way in and a way to join', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertSee('Sign in');
});

it('links the pricing page from the header of every marketing page', function (): void {
    foreach (['/', '/pricing', '/privacy'] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee(route('pricing'), escape: false);
    }
});

it('marks the current page in the header nav', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('aria-current="page"', escape: false);
});

it('carries a mobile menu with the same links as the bar', function (): void {
    // The bar hides its nav, language and sign-in below `md`, so everything
    // it drops has to live in the panel behind the toggle.
    $response = $this->get('/')->assertOk();
    $html = $response->getContent();

    expect($html)->toBeString()
        ->toContain('aria-controls="marketing-menu"')
        ->toContain('id="marketing-menu"');

    $panel = Str::after((string) $html, 'id="marketing-menu"');

    expect($panel)->toContain(route('pricing'))
        ->toContain(route('login'));
});

it('offers a signed-in visitor the app instead of a sign-in link', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('Open app')
        ->assertDontSee('Sign in');
});
