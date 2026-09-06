<?php declare(strict_types=1);

use App\Models\User;
use Symfony\Component\DomCrawler\Crawler;

it('offers a guest both a way in and a way to join', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertSee('Sign in');
});

/**
 * @return list<string>
 */
function hrefsWithin(string $html, string $selector): array
{
    return new Crawler($html)->filter($selector)->each(
        static fn (Crawler $node): string => (string) $node->attr('href'),
    );
}

it('links the pricing page from the header of every marketing page', function (): void {
    foreach (['/', '/pricing', '/privacy'] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();

        // Scoped to the header: every page also links pricing from its
        // footer, so a page-wide assertion would pass with no header link.
        expect(hrefsWithin($html, 'header a'))->toContain(route('pricing'));
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
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-controls="marketing-menu"');

    // Scoped to the panel itself, not the rest of the page: the hero and
    // the footer carry these links too.
    expect(hrefsWithin($html, '#marketing-menu a'))
        ->toContain(route('pricing'))
        ->toContain(route('login'));
});

it('offers a signed-in visitor the app instead of a sign-in link', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('Open app')
        ->assertDontSee('Sign in');
});
