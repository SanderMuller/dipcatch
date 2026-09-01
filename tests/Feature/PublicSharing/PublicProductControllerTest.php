<?php declare(strict_types=1);

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

    $response->assertOk()
        ->assertSee('Acme Headphones', escape: false)
        ->assertSee('EUR 85.00', escape: false)
        ->assertSee('bol.com', escape: false);
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

    $response->assertSee('visible.test', escape: false)
        ->assertDontSee('inactive.test', escape: false);
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

    $response->assertSee('in-stock.test', escape: false)
        ->assertDontSee('oos.test', escape: false);
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

    $response->assertSee('healthy.test', escape: false)
        ->assertDontSee('dead.test', escape: false);
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

    $response->assertSee('priced.test', escape: false)
        ->assertDontSee('nullprice.test', escape: false);
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

    $this->get('/p/' . str_repeat('a', 32))
        ->assertOk()
        ->assertSee('DipCatch', escape: false);  // footer link signals successful render
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

    $response->assertSee('<meta property="og:title" content="Acme Headphones">', escape: false)
        ->assertSee('<meta property="og:description" content="Tracked on DipCatch: cheapest at EUR 85.00">', escape: false)
        ->assertSee('<meta property="og:image" content="https://example.com/img.png">', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false)
        ->assertSee('<meta name="twitter:image" content="https://example.com/img.png">', escape: false);
});

test('OG image is omitted when image_url uses a non-http scheme', function (): void {
    makeSharedProduct(['image_url' => 'javascript:alert(1)']);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSee('<meta name="twitter:card" content="summary">', escape: false)
        ->assertDontSee('og:image', escape: false)
        ->assertDontSee('twitter:image', escape: false)
        ->assertDontSee('javascript:alert', escape: false);
});

test('OG image is omitted when image_url is null', function (): void {
    makeSharedProduct(['image_url' => null]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertSee('<meta name="twitter:card" content="summary">', escape: false)
        ->assertDontSee('og:image', escape: false);
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

    $response->assertOk()
        ->assertSee('Price (last 90 days)', escape: false)
        ->assertSee('id="price-history-chart"', escape: false)
        ->assertSee('"y":"100.00"', escape: false)
        ->assertSee('"y":"85.00"', escape: false)
        ->assertSee('cdn.jsdelivr.net/npm/chart.js', escape: false);
});

test('chart + Chart.js script are not emitted when history is empty', function (): void {
    $product = makeSharedProduct();
    Shop::factory()->for($product)->create([
        'current_price' => '85.00',
    ]);

    $response = $this->get('/p/' . str_repeat('a', 32));

    $response->assertOk()
        ->assertDontSee('Price (last 90 days)', escape: false)
        ->assertDontSee('id="price-history-chart"', escape: false)
        ->assertDontSee('cdn.jsdelivr.net/npm/chart.js', escape: false);
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

    $response->assertOk()
        ->assertDontSee('"y":"999.99"', escape: false)
        ->assertSee('"y":"85.00"', escape: false);
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

    $response->assertOk()
        ->assertSee('No live price available right now', escape: false)
        ->assertDontSee('EUR 85.00', escape: false)
        ->assertDontSee('gone.test', escape: false);
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

    $response->assertOk()
        ->assertSee('EUR 8.45 /kg', escape: false);
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

    $response->assertOk()
        ->assertDontSee('/kg', escape: false)
        ->assertDontSee(' /l', escape: false)
        ->assertDontSee('/stuk', escape: false);
});

test('throttle: the 121st request in a minute returns 429', function (): void {
    makeSharedProduct();
    $slug = '/p/' . str_repeat('a', 32);

    for ($i = 0; $i < 120; $i++) {
        $this->get($slug)->assertOk();
    }
    $this->get($slug)->assertStatus(429);
});
