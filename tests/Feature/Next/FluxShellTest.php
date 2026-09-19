<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\User;

/**
 * The main nav bar's inner HTML. Fails the test when the bar is missing
 * rather than handing back an empty string, which would quietly satisfy
 * every "does not contain" claim made about it.
 */
function mainNav(string $body): string
{
    expect($body)->toMatch('#<nav [^>]*aria-label="Main"#');

    preg_match('#<nav [^>]*aria-label="Main"[^>]*>(.*?)</nav>#s', $body, $matches);

    return $matches[1] ?? '';
}

it('sends an unverified user away from the flux app', function (): void {
    $this->actingAs(User::factory()->unverified()->create());

    // Same rule the Filament panel enforced through its authMiddleware, so the
    // access contract does not change at cutover.
    $this->get(route('app.dashboard'))->assertRedirect();
});

it('sends a guest to login', function (): void {
    $this->get(route('app.dashboard'))->assertRedirect(route('login'));
});

it('lets a verified user in', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertOk();
});

it('offers the admin shortcut only to an admin', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->get(route('app.dashboard'))->assertOk()->assertSee('Admin panel');

    $this->actingAs(User::factory()->create(['is_admin' => false]));
    $this->get(route('app.dashboard'))->assertOk()->assertDontSee('Admin panel');
});

it('still refuses the admin panel to a non-admin', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    // The menu entry is a shortcut, never the thing that grants access.
    $this->get('/admin')->assertForbidden();
});

it('registers every route the migration will need', function (string $name): void {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'app.dashboard',
    'app.products.index',
    'app.products.create',
    'app.products.create-manual',
    'app.products.show',
    'app.products.edit',
    'app.billing',
    'app.notifications',
    'app.connections',
]);

it('links the shell navigation at every section', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))
        ->assertOk()->assertSee('Products')->assertSeeHtml('Plan &amp; billing')
        ->assertSee('Notifications')
        ->assertSee('Connections');
});

it('drops the starter kit links', function (): void {
    $this->actingAs(User::factory()->create());

    $body = (string) $this->get(route('app.dashboard'))->getContent();

    expect($body)->not->toContain('livewire-starter-kit')
        ->and($body)->not->toContain('laravel.com/docs/starter-kits');
});

it('detects the timezone on any authenticated page, not only settings', function (): void {
    $this->actingAs(User::factory()->create(['timezone_detected_at' => null]));

    // It fired panel-wide under Filament. A user who never opens settings would
    // otherwise keep a wrong digest hour.
    $this->get(route('app.dashboard'))->assertOk()->assertSeeHtml('auto-detect');
});

it('does not re-detect a timezone the user has already settled', function (): void {
    $this->actingAs(User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'timezone_detected_at' => now(),
    ]));

    $this->get(route('app.dashboard'))->assertOk()->assertDontSeeHtml('auto-detect');
});

it('offers a way back to the marketing home page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Home page')
        ->assertSeeHtml('data-test="home-page-nav"')
        ->assertSeeHtml('href="' . route('home') . '"');
});

it('lays the shell out as a horizontal header, not a sidebar', function (): void {
    $this->actingAs(User::factory()->create());

    $body = (string) $this->get(route('app.dashboard'))->getContent();

    // The nav bar itself: primary links, a "More" dropdown for the rest and
    // a search bar, in place of the Flux sidebar.
    expect($body)->toContain('data-test="more-menu-button"')
        ->and($body)->toContain('data-test="app-search-bar"')
        ->and($body)->not->toContain('data-flux-sidebar');
});

it('leaves the dashboard out of the bar, because the logo goes there', function (): void {
    $this->actingAs(User::factory()->create());

    $body = (string) $this->get(route('app.dashboard'))->getContent();

    $nav = mainNav($body);

    expect($nav)->not->toBeEmpty()
        ->and($nav)->not->toContain('Dashboard')
        ->and($nav)->toContain('Products');
});

it('does not mark Products as the current page on a product page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    $body = (string) $this->get(route('app.products.show', $product))->getContent();

    $nav = mainNav($body);

    // The pill read as "you are here", so the way back to the list looked dead.
    expect($nav)->toContain('Products')
        ->and($nav)->not->toContain('aria-current');
});

it('offers a way into the stats page from the shell, not only from the dashboard card', function (): void {
    $this->actingAs(User::factory()->create());

    // Asked for from the product list, not the dashboard: the savings tile
    // carries this same href, so a dashboard request would pass with the shell
    // entry deleted.
    $this->get(route('app.products.index'))
        ->assertOk()
        ->assertSeeHtml('href="' . route('app.stats') . '"')
        ->assertSee('Stats');
});

it('does not mark the logo as the current page while the stats page is open', function (): void {
    $this->actingAs(User::factory()->create());

    $logo = static function (string $url): string {
        preg_match('#<a[^>]*aria-label="Dashboard"[^>]*>#', (string) test()->get($url)->getContent(), $matches);

        expect($matches)->not->toBeEmpty();

        // The expectation above is what proves the logo is there; the fallback
        // only satisfies the static analyser, and cannot be reached.
        return $matches[0] ?? '';
    };

    // The logo points at the dashboard. Claiming "you are here" on another
    // page tells a screen reader the wrong thing.
    expect($logo(route('app.dashboard')))->toContain('aria-current="page"')
        ->and($logo(route('app.stats')))->not->toContain('aria-current="page"');
});
