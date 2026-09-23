<?php declare(strict_types=1);

use App\Enums\ConsumerPriceIssue;
use App\Enums\PackExclusion;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;

beforeEach(function (): void {
    clearRedisRateLimiter('public-product');
});

/**
 * @param  array<string, mixed>  $attrs
 */
function makeSharedProduct(array $attrs = []): Product
{
    /** @phpstan-ignore argument.type */
    return Product::factory()->for(User::factory())->create([
        'share_slug' => str_repeat('a', 32),
        'title' => 'Acme Headphones',
        'currency' => 'EUR',
        'cheapest_price' => '85.00',
        'image_url' => 'https://example.com/img.png',
        ...$attrs,
    ]);
}

test('happy path: valid slug renders product summary + shop list', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '85.00',
        'currency' => 'EUR',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('Acme Headphones')->assertSeeHtml('€85.00')->assertSeeHtml('bol.com');
});

test('bundle price always shows quantity total and single-item price', function (): void {
    $product = makeSharedProduct(['cheapest_price' => '2.00']);
    Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'currency' => 'EUR',
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);

    // Sold by the litre, so the page leads per litre with the regular price per
    // litre struck beside it, and the pack line carries the deal terms.
    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtmlInOrder(['€1.33 /l', '€1.90 /l', '€2.00 for 1.5 L', '2 for €4.00', 'Compared across'])
        ->assertSeeHtml('or €2.85 each')
        ->assertSeeHtml('title="Regular price"')
        ->assertSeeHtml('Tracked on DipCatch: best value €1.33 /l at jumbo.com (€2.00 for 1.5 L · 2 for €4.00 · or €2.85 each)');
});

test('equal prices use the same stable shop order as the cheapest-price engine', function (): void {
    $product = makeSharedProduct(['cheapest_price' => '2.00']);
    Shop::factory()->for($product)->create([
        'url' => 'https://oldest.test/p/fanta',
        'current_price' => '2.00',
        'created_at' => now()->subMinute(),
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://newer.test/p/fanta',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'created_at' => now(),
    ]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()->assertSeeHtmlInOrder(['oldest.test', 'newer.test'])->assertSeeHtml('<meta property="og:description" content="Tracked on DipCatch: cheapest at €2.00">');
});

test('headline and shop list ignore offers in another currency', function (): void {
    $product = makeSharedProduct(['currency' => 'EUR', 'cheapest_price' => '2.00']);
    Shop::factory()->for($product)->create([
        'url' => 'https://wrong-currency.test/p/fanta',
        'current_price' => '1.00',
        'currency' => 'GBP',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://euro.test/p/fanta',
        'current_price' => '2.00',
        'currency' => 'EUR',
    ]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()->assertSeeHtml('euro.test')->assertDontSeeHtml('wrong-currency.test')->assertSeeHtml('<meta property="og:description" content="Tracked on DipCatch: cheapest at €2.00">');
});

test('unknown slug returns 404', function (): void {
    $this->get('/p/' . str_repeat('z', 32))->assertNotFound();
});

test('wrong slug length rejected by route regex (32-char exact)', function (): void {
    $this->get('/p/abc')->assertNotFound();        // too short
    $this->get('/p/' . str_repeat('a', 31))->assertNotFound();
    $this->get('/p/' . str_repeat('a', 33))->assertNotFound();
    $this->get('/p/' . str_repeat('a', 100))->assertNotFound();
});

test('null share_slug on a real product is not reachable', function (): void {
    Product::factory()->create(['share_slug' => null]);

    // No slug to fetch by. The wildcard test ensures we don't accidentally
    // match the empty/null slug via a degenerate query.
    $this->get('/p/' . str_repeat('a', 32))->assertNotFound();
});

test('eligibility filter omits inactive shops', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://visible.test/p/1',
        'current_price' => '85.00',
    ]);
    Shop::factory()->for($product)->inactive()->create([
        'url' => 'https://inactive.test/p/1',
        'current_price' => '70.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('visible.test')->assertDontSeeHtml('inactive.test');
});

test('eligibility filter omits out-of-stock shops', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://in-stock.test/p/1',
        'current_price' => '85.00',
    ]);
    Shop::factory()->for($product)->outOfStock()->create([
        'url' => 'https://oos.test/p/1',
        'current_price' => '70.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('in-stock.test')->assertDontSeeHtml('oos.test');
});

test('eligibility filter omits dead shops', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://healthy.test/p/1',
        'current_price' => '85.00',
    ]);
    Shop::factory()->for($product)->dead()->create([
        'url' => 'https://dead.test/p/1',
        'current_price' => '70.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('healthy.test')->assertDontSeeHtml('dead.test');
});

test('eligibility filter omits shops with null current_price', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://priced.test/p/1',
        'current_price' => '85.00',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://nullprice.test/p/1',
        'current_price' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('priced.test')->assertDontSeeHtml('nullprice.test');
});

test('private shop fields never appear in the response body', function (): void {
    $product = makeSharedProduct();
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '85.00',
        'notes' => 'SECRET_NOTE_DO_NOT_LEAK',
        'price_selector' => '.price-selector-SECRET',
        'title_selector' => '.title-selector-SECRET',
        'image_selector' => '.image-selector-SECRET',
    ]);
    PriceCheck::factory()->failed()->create([
        'shop_id' => $shop->id,
        'error' => 'SECRET_ERROR_LEAK',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()
        ->assertDontSee('SECRET_NOTE_DO_NOT_LEAK')
        ->assertDontSee('price-selector-SECRET')
        ->assertDontSee('title-selector-SECRET')
        ->assertDontSee('image-selector-SECRET')
        ->assertDontSee('SECRET_ERROR_LEAK');
});

test('private product fields never appear in the response body', function (): void {
    $product = makeSharedProduct([
        'drop_threshold_pct' => '42.42',
        'drop_threshold_abs' => '13.37',
        'last_notified_price' => '99.99',
    ]);
    Shop::factory()->for($product)->create(['current_price' => '85.00']);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()
        ->assertDontSee('42.42')   // drop_threshold_pct
        ->assertDontSee('13.37')   // drop_threshold_abs
        ->assertDontSee('99.99');  // last_notified_price
});

test('guest viewer sees the page without an auth redirect', function (): void {
    makeSharedProduct();

    $this->get('/p/' . str_repeat('a', 32))->assertOk()->assertSeeHtml('DipCatch');  // footer link signals successful render
});

test('response includes X-Robots-Tag noindex header', function (): void {
    makeSharedProduct();

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('emits OG + Twitter meta tags with safeImageUrl-guarded image', function (): void {
    $product = makeSharedProduct(['image_url' => 'https://example.com/img.png']);
    Shop::factory()->for($product)->create(['current_price' => '85.00']);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('<meta property="og:title" content="Acme Headphones">')->assertSeeHtml('<meta property="og:description" content="Tracked on DipCatch: cheapest at €85.00">')->assertSeeHtml('<meta property="og:image" content="https://example.com/img.png">')->assertSeeHtml('<meta name="twitter:card" content="summary_large_image">')->assertSeeHtml('<meta name="twitter:image" content="https://example.com/img.png">');
});

test('OG image is omitted when image_url uses a non-http scheme', function (): void {
    makeSharedProduct(['image_url' => 'javascript:alert(1)']);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('<meta name="twitter:card" content="summary">')->assertDontSeeHtml('og:image')->assertDontSeeHtml('twitter:image')->assertDontSeeHtml('javascript:alert');
});

test('OG image is omitted when image_url is null', function (): void {
    makeSharedProduct(['image_url' => null]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSeeHtml('<meta name="twitter:card" content="summary">')->assertDontSeeHtml('og:image');
});

test('chart payload renders inline with started/ended segment data', function (): void {
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '100.00',
        'started_at' => now()->subDays(10),
        'ended_at' => now()->subDays(5),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);
    Shop::factory()->for($product)->create(['current_price' => '85.00']);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('Price (last 90 days)')->assertSeeHtml('id="price-history-chart"')->assertSeeHtml('"y":"100.00"')->assertSeeHtml('"y":"85.00"')->assertSeeHtml('cdn.jsdelivr.net/npm/chart.js');
});

test('chart + Chart.js script are not emitted when history is empty', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'current_price' => '85.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertDontSeeHtml('Price (last 90 days)')->assertDontSeeHtml('id="price-history-chart"')->assertDontSeeHtml('cdn.jsdelivr.net/npm/chart.js');
});

test('chart payload excludes history segments older than 90 days', function (): void {
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '999.99',  // sentinel — should NOT appear
        'started_at' => now()->subDays(120),
        'ended_at' => now()->subDays(100),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(30),
        'ended_at' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertDontSeeHtml('"y":"999.99"')->assertSeeHtml('"y":"85.00"');
});

test('stale cheapest_price is suppressed when no shop is currently eligible', function (): void {
    // Denormalized cheapest_price is recomputed async after each CheckShopPrice.
    // Between "all shops became ineligible" and the next recompute the column
    // carries a stale number; rendering it next to "0 shops" misleads.
    $product = makeSharedProduct(['cheapest_price' => '85.00']);
    Shop::factory()->for($product)->dead()->create([
        'url' => 'https://gone.test/p/1',
        'current_price' => '85.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('No live price available right now')->assertDontSeeHtml('€85.00')->assertDontSeeHtml('gone.test');
});

test('shop with a pack size renders its unit price under the price', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '1.69',
        'currency' => 'EUR',
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('€8.45 /kg');
});

test('shop without a pack size shows no unit price', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '85.00',
        'currency' => 'EUR',
        'pack_quantity' => null,
        'pack_unit' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertDontSeeHtml('/kg')->assertDontSeeHtml(' /l')->assertDontSeeHtml('/piece');
});

test('throttle: the 121st request in a minute returns 429', function (): void {
    makeSharedProduct();
    $slug = '/p/' . str_repeat('a', 32);

    for ($i = 0; $i < 120; $i++) {
        $this->get($slug)->assertOk();
    }
    $this->get($slug)->assertStatus(429);
});

test('chart payload includes a long-running segment that started before the window', function (): void {
    // The cheapest price is stored as segments. A price that has not moved
    // for over a year is one open segment that started before any window, so
    // matching on started_at alone renders an empty chart for exactly the
    // products that are working best.
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(400),
        'ended_at' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('id="price-history-chart"')->assertSeeHtml('"y":"85.00"');
});

test('chart payload clips a segment that started before the window to the cutoff', function (): void {
    // The heading above the canvas promises 90 days and the time axis has no
    // floor, so an unclipped 400-day-old start stretches the plot to 400 days
    // under a "last 90 days" heading.
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(400),
        'ended_at' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('"y":"85.00"')->assertDontSeeHtml(now()->subDays(400)->format('Y-m-d'))->assertSeeHtml(now()->subDays(90)->format('Y-m-d'));
});

test('chart payload includes a segment that started before the window and ended inside it', function (): void {
    // The most common shape in production: a price that held for months and
    // then moved last week. The earlier segment matches on neither its start
    // nor an open end — only on `ended_at` falling inside the window.
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '120.00',
        'started_at' => now()->subDays(300),
        'ended_at' => now()->subDays(20),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(20),
        'ended_at' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('"y":"120.00"')->assertSeeHtml('"y":"85.00"');
});

test('chart payload never carries another products segments', function (): void {
    // The window predicate is a chain of ORs, so the product filter is the
    // only thing keeping one account's prices off another's public page.
    // Laravel groups a named scope's wheres for us; this pins the outcome
    // rather than the mechanism.
    $product = makeSharedProduct();
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    $other = Product::factory()->for(User::factory())->create(['share_slug' => null]);
    ProductCheapestHistory::factory()->for($other)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '777.77',
        'started_at' => now()->subDays(400),
        'ended_at' => null,
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()->assertSeeHtml('"y":"85.00"')->assertDontSeeHtml('"y":"777.77"');
});

test('the shared page names both answers and says why a shop has no unit price', function (): void {
    $product = makeSharedProduct(['cheapest_price' => '2.55']);

    foreach ([
        ['host' => 'dirk.nl', 'price' => '2.55', 'quantity' => '240.00', 'unit' => 'g'],
        ['host' => 'jumbo.com', 'price' => '6.15', 'quantity' => '840.00', 'unit' => 'g'],
        ['host' => 'ah.nl', 'price' => '6.59', 'quantity' => '30.00', 'unit' => 'piece'],
    ] as $row) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $row['host'] . '/p/1'])
            ->forceFill([
                'currency' => 'EUR',
                'current_price' => $row['price'],
                'current_in_stock' => true,
                'pack_quantity' => $row['quantity'],
                'pack_unit' => $row['unit'],
            ])->save();
    }

    $response = $this->get('/p/' . str_repeat('a', 32));

    // The vissticks: Dirk is the smallest outlay, Jumbo is 31% better per kilo.
    // Calling the first row "Cheapest" without saying on what basis is the claim
    // this page used to make.
    $response->assertOk()
        ->assertSeeHtml('Lowest price')
        ->assertSeeHtml('Best value')
        ->assertSeeHtml('Sold by the piece — no item size to compare')
        ->assertDontSeeHtml('>Cheapest<');
});

test('the shared page dates the price by its last successful read', function (): void {
    $product = makeSharedProduct(['cheapest_price' => '85.00']);

    Shop::factory()->for($product)->create(['url' => 'https://failing.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR',
            'current_price' => '85.00',
            'current_in_stock' => true,
            'last_success_at' => now()->subDays(8),
            'last_checked_at' => now()->subHours(2),
            'consecutive_failures' => 5,
        ])->save();

    $response = $this->get('/p/' . str_repeat('a', 32));

    // A shared link is the surface a reader trusts most and can correct least.
    $response->assertOk()
        ->assertSeeHtml('and not read since')
        ->assertDontSeeHtml('Last checked');
});

test('markdown copy: the shared page as markdown, with no private field in it', function (): void {
    $product = makeSharedProduct(['title' => 'Beans & more', 'drop_threshold_pct' => '12.50']);
    Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/beans',
        'current_price' => '6.00',
        'currency' => 'EUR',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'notes' => 'coupon SECRET10',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/beans',
        'current_price' => '9.00',
        'currency' => 'EUR',
        'pack_quantity' => 1000,
        'pack_unit' => 'g',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32) . '.md');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->getContent())
        ->toStartWith("# Beans & more\n")
        ->not->toContain('## Best price now')
        ->toContain("## Best value\n\n€9.00 /kg at [ah.nl](<https://ah.nl/p/beans>)\n\n€9.00 for 1 kg")
        ->toContain('> Lowest price: €6.00 for 500 g at bol.com. That is 33% more per kilo than the best value.')
        ->toContain('Compared across 2 shops tracked.')
        ->toContain('| [bol.com](<https://bol.com/p/beans>) | €12.00 /kg | €6.00 for 500 g |')
        ->not->toContain('SECRET10')
        ->not->toContain('drop')
        ->not->toContain('/app/');
});

test('markdown copy: a shared product with no live price says so', function (): void {
    makeSharedProduct();

    expect($this->get('/p/' . str_repeat('a', 32) . '.md')->assertOk()->getContent())
        ->toContain('No live price available right now.')
        ->not->toContain('## Shops');
});

test('markdown copy: an unknown or withdrawn slug is a 404', function (): void {
    makeSharedProduct(['share_slug' => null]);

    $this->get('/p/' . str_repeat('a', 32) . '.md')->assertNotFound();
});

test('a shop outside the unit comparison shows its reason and pack price, never its own unit figure', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/bars', 'current_price' => '4.99', 'pack_quantity' => '660.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/bars', 'current_price' => '5.49', 'pack_quantity' => '660.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://fitnesscandy.nl/p/bars', 'current_price' => '3.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece']);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeText('€7.56 /kg')
        ->assertSeeText(PackExclusion::SoldByThePiece->label())
        ->assertSeeText('€3.00')
        ->assertDontSeeText('/piece');
});

test('an estimated size is marked on the public page', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/chips', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/chips', 'current_price' => '1.79', 'pack_quantity' => null, 'pack_unit' => null]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeTextInOrder(['€8.95 /kg', '€1.79 for 200 g (estimated)']);
});

test('a trade-only price wins neither answer on the public page', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/chips', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create([
        'url' => 'https://wholesale.test/p/chips',
        'current_price' => '0.99',
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
        'consumer_price_issue' => ConsumerPriceIssue::TradeOnly,
    ]);

    $html = (string) preg_replace('/\s+/', ' ', $this->get('/p/' . str_repeat('a', 32))->assertOk()->content());

    expect($html)->toContain('Tracked on DipCatch: best value €8.45 /kg at ah.nl')
        ->and(substr_count($html, '>Lowest price<'))->toBe(1)
        ->and($html)->toContain(ConsumerPriceIssue::TradeOnly->label());

    preg_match_all('/<li>(.*?)<\/li>/s', $html, $rows);
    $wholesale = collect($rows[1])->first(fn (string $row): bool => str_contains($row, 'wholesale.test'));
    $ah = collect($rows[1])->first(fn (string $row): bool => str_contains($row, 'ah.nl/p/chips'));

    expect($wholesale)->not->toContain('>Lowest price<')->not->toContain('>Best value<')
        ->and($ah)->toContain('>Lowest price<')->toContain('>Best value<');
});

test('sold-out sized shops still give the public page its unit', function (): void {
    // The only sized shop is sold out: it is not listed, but it still decides
    // what the product is measured in, as on the owner page. The listed shop
    // states no size, so it shows the size it inherits, marked as estimated.
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/chips', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'current_in_stock' => false]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/chips', 'current_price' => '1.79', 'pack_quantity' => null, 'pack_unit' => null]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeTextInOrder(['€8.95 /kg', '€1.79 for 200 g (estimated)'])
        ->assertDontSeeText('ah.nl');
});

test('the public chart plots the best value per unit, and falls back to the pack price for a history in another unit', function (): void {
    $perKilo = makeSharedProduct();
    $shop = Shop::factory()->for($perKilo)->create(['url' => 'https://ah.nl/p/chips', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    ProductCheapestHistory::factory()->for($perKilo)->create(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'started_at' => now()->subDays(5), 'ended_at' => null]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtml('Price per kilo (last 90 days)')
        ->assertSeeHtml('"y":"8.4500"');

    $perKilo->forceFill(['share_slug' => null])->save();
    $perPiece = makeSharedProduct();
    $pieces = Shop::factory()->for($perPiece)->create(['url' => 'https://ah.nl/p/bars', 'current_price' => '4.99', 'pack_quantity' => '12.00', 'pack_unit' => 'piece']);
    ProductCheapestHistory::factory()->for($perPiece)->create(['cheapest_shop_id' => $pieces->id, 'cheapest_price' => '4.99', 'pack_quantity' => '660.00', 'pack_unit' => 'g', 'started_at' => now()->subDays(5), 'ended_at' => null]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtml('Price (last 90 days)')
        ->assertSeeHtml('"y":"4.99"');
});

test('a trade-only price never heads a shared product compared on pack prices', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/cam', 'current_price' => '299.00']);
    Shop::factory()->for($product)->create(['url' => 'https://wholesale.test/p/cam', 'current_price' => '249.00', 'consumer_price_issue' => ConsumerPriceIssue::ExcludesVat]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtml('Tracked on DipCatch: cheapest at €299.00');

    expect($this->get('/p/' . str_repeat('a', 32) . '.md')->content())
        ->toContain("## Best price now\n\n€299.00 at [bol.com]");
});

test('the public chart stays on the pack price when most of the history is in another unit', function (): void {
    $product = makeSharedProduct();
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/bars', 'current_price' => '4.99', 'pack_quantity' => '12.00', 'pack_unit' => 'piece']);

    foreach ([[40, 30, '660.00', 'g'], [30, 20, '660.00', 'g'], [20, 10, '660.00', 'g'], [10, null, '12.00', 'piece']] as [$from, $to, $quantity, $unit]) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id, 'cheapest_price' => '4.99', 'pack_quantity' => $quantity, 'pack_unit' => $unit,
            'started_at' => now()->subDays($from), 'ended_at' => $to === null ? null : now()->subDays($to),
        ]);
    }

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtml('Price (last 90 days)')
        ->assertDontSeeHtml('Price per piece (last 90 days)');
});

test('a per-unit chart point carries no deal from the lowest-price shop', function (): void {
    $product = makeSharedProduct();
    $small = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/t', 'current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece']);
    $big = Shop::factory()->for($product)->create(['url' => 'https://kruidvat.nl/p/t', 'current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $small->id, 'cheapest_price' => '12.99', 'single_item_price' => '14.99', 'bundle_quantity' => 2, 'bundle_total_price' => '25.98',
        'best_value_shop_id' => $big->id, 'best_value_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece',
        'started_at' => now()->subDays(5), 'ended_at' => null,
    ]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeHtml('"y":"0.0275"')
        ->assertDontSeeHtml('"bundle":"2 for');
});

test('a trade-only public row leads with its reason, not a price per unit', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/chips', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://wholesale.test/p/chips', 'current_price' => '0.99', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'consumer_price_issue' => ConsumerPriceIssue::TradeOnly]);

    $this->get('/p/' . str_repeat('a', 32))->assertOk()
        ->assertSeeText('€8.45 /kg')
        ->assertDontSeeText('€4.95 /kg');
});
