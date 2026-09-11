<?php declare(strict_types=1);

use App\Support\ShopPages;

it('serves a page for every supported shop', function (): void {
    foreach (ShopPages::all() as $shop) {
        $this->get($shop->url())
            ->assertOk()
            ->assertSee($shop->name, escape: false)
            ->assertSee($shop->host, escape: false);
    }
})->skip(fn (): bool => ShopPages::all() === [], 'No shops configured.');

it('refuses a host that is not supported', function (): void {
    $this->get('/shops/not-a-shop')->assertNotFound();
});

it('states the offer window only for the shops whose adapter reads one', function (): void {
    // Albert Heijn goes through the mobile API, which returns the Bonus
    // window; bol.com has no promotion parsing, so the page must not claim it.
    $this->get(route('shop', ['slug' => 'ah-nl']))
        ->assertOk()
        ->assertSee('is read with the date it ends', escape: false);

    $this->get(route('shop', ['slug' => 'bol-com']))
        ->assertOk()
        ->assertDontSee('is read with the date it ends', escape: false);
});

it('warns that a Jumbo multi-buy leaves the shelf price alone', function (): void {
    // Documented in JumboAdapter: "1+1 gratis" does not lower the price, so a
    // page that promised otherwise would sell an alert that never fires.
    $this->get(route('shop', ['slug' => 'jumbo-com']))
        ->assertOk()
        ->assertSee('leave the shelf price alone', escape: false);
});

it('links each shop page to the categories that name it, and no others', function (): void {
    $shop = ShopPages::find('ah-nl');

    // Asserted on the page object, not on the markup: the footer links every
    // category from every page, so the rendered HTML cannot tell the two
    // kinds of link apart.
    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['groceries', 'pet-food', 'coffee', 'beauty']);

    $this->get(route('shop', ['slug' => 'ah-nl']))
        ->assertOk()
        ->assertSee('What people track at Albert Heijn', escape: false);
});

it('links Amazon UK and US shops to groceries as well as pet food, coffee and filters', function (string $slug): void {
    $shop = ShopPages::find($slug);

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['groceries', 'pet-food', 'coffee', 'filters', 'beauty']);
})->with(['amazon-com', 'amazon-co-uk']);

it('links other Amazon country shops to pet food, coffee and filters', function (string $slug): void {
    $shop = ShopPages::find($slug);

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['pet-food', 'coffee', 'filters', 'beauty']);
})->with(['amazon-de', 'amazon-com-be']);

it('links Etos, The Ordinary, Lookfantastic, Cult Beauty, Ulta and Walmart to the beauty category only', function (string $slug): void {
    $shop = ShopPages::find($slug);

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['beauty']);
})->with(['etos-nl', 'theordinary-com', 'lookfantastic-com', 'cultbeauty-com', 'ulta-com', 'walmart-com']);

it('states that Dierapotheker reads the article number', function (): void {
    $this->get(route('shop', ['slug' => 'dierapotheker-nl']))
        ->assertOk()
        ->assertSee('article number', escape: false);
});

it('lists every shop on the hub, with a link to each', function (): void {
    $response = $this->get(route('shops'))->assertOk();

    foreach (ShopPages::all() as $shop) {
        $response->assertSee(route('shop', ['slug' => $shop->slug]), escape: false);
    }
});

it('carries a canonical, both hreflang alternates and the FAQ graph', function (): void {
    $content = (string) $this->get(route('shop', ['slug' => 'dirk-nl']))->assertOk()->getContent();

    expect($content)->toContain('<link rel="canonical" href="' . route('shop', ['slug' => 'dirk-nl']) . '">')
        ->toContain('hreflang="nl" href="' . route('shop', ['slug' => 'dirk-nl', 'lang' => 'nl']))
        ->toContain('"@type":"FAQPage"')
        ->toContain('"@type":"BreadcrumbList"');
});

it('renders the Dutch page in Dutch', function (): void {
    $this->get(route('shop', ['slug' => 'ah-nl', 'lang' => 'nl']))
        ->assertOk()
        ->assertSee('Prijsalarm voor Albert Heijn', escape: false)
        ->assertDontSee('Price alerts for Albert Heijn', escape: false);
});

it('reaches every shop page from the homepage', function (): void {
    $response = $this->get('/')->assertOk();

    foreach (ShopPages::all() as $shop) {
        $response->assertSee(route('shop', ['slug' => $shop->slug]), escape: false);
    }
});
