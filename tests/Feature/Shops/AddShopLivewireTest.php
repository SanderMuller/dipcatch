<?php declare(strict_types=1);

use App\Enums\SupportRequestType;
use App\Livewire\Shops\AddShop;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

test('probe success shows preview state with snapshot data', function (): void {
    Http::fake(fakeJsonLdOffer());
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('snapshot.price', '50.00')
        ->assertSet('host', 'shop.example.com')
        ->assertSee('€50.00')
        ->assertDontSee('EUR 50.00');
});

test('confirm persists offer + initial price_check + recomputes cheapest', function (): void {
    Http::fake(fakeJsonLdOffer());
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm')
        ->assertSet('state', 'idle');

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop)->not->toBeNull()
        ->and($shop->url)->toBe('https://shop.example.com/p/1')
        ->and($shop->host)->toBe('shop.example.com')
        ->and((string) $shop->current_price)->toBe('50.00')
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);

    $product->refresh();
    expect($product->cheapest_shop_id)->toBe($shop->id)
        ->and((string) $product->cheapest_price)->toBe('50.00');
});

test('cancel returns to idle and does not persist', function (): void {
    Http::fake(fakeJsonLdOffer());
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('cancel')
        ->assertSet('state', 'idle');

    expect(Shop::query()->count())->toBe(0);
});

test('duplicate URL surfaces duplicate error without fetch', function (): void {
    Http::fake(); // any HTTP would be an unexpected call
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1?utm_source=x')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'duplicate');
});

test('robots-blocked URL is rejected without persisting', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
        'https://shop.example.com/p/1' => Http::response('<html>ok</html>', 200),
    ]);
    $product = Product::factory()->create();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'robots_disallowed')
        ->assertDontSee('Request a shop');

    expect(Shop::query()->count())->toBe(0);
});

test('an unservable shop explains itself instead of printing the raw error code', function (): void {
    Http::fake(); // any HTTP call would be an unexpected fetch — the check is host-based, before any fetch.
    $product = Product::factory()->create();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://www.plus.nl/product/fanta-orange-fles-1500-ml-991700')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'shop_not_servable')
        ->assertSee('builds its prices in the browser')
        ->assertDontSee('shop_not_servable');

    Http::assertNothingSent();
});

test('currency mismatch is surfaced inline', function (): void {
    Http::fake(fakeJsonLdOffer('https://shop.example.com/p/1', '50.00', 'GBP'));
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'currency_mismatch')
        ->assertSet('errorContext.expected', 'EUR')
        ->assertSet('errorContext.actual', 'GBP');
});

test('extraction failure flips into manual_selector state without persisting', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);
    $product = Product::factory()->create();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'manual_selector')
        ->assertSet('errorCode', 'no_adapter_matched')
        ->assertSee('Request a shop')
        ->assertSee(route('app.support', [
            'type' => SupportRequestType::ShopRequest->value,
            'shop_url' => 'https://shop.example.com/p/1',
        ]));

    expect(Shop::query()->count())->toBe(0);
});

test('ExtractionFailed with non-manual reason stays in error state, not manual_selector', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    // jsonld_no_offer is an extraction reason but NOT a manual-selector
    // trigger (those are 'no_adapter_matched' + 'user_selector_*'). Ensure
    // the bridge in AddShop::handleFailure() doesn't over-match — without
    // this assertion the bridge would silently divert all extraction
    // failures into the selector form.
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'No-Offer Product',
        // Deliberately no `offers` key → JsonLdAdapter::failed('jsonld_no_offer').
    ], JSON_THROW_ON_ERROR);
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'extraction_failed')
        ->assertSee('DipCatch could not read a price from that page')
        ->assertSee('Request a shop')
        ->assertSee(rawurlencode('https://shop.example.com/p/1'), escape: false);

    expect(Shop::query()->count())->toBe(0);
});

test('manual selector flow extracts price and persists offer with selectors', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>Manual Item</h1>
  <span class="cost">€ 19,95</span>
</body></html>
HTML;
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'manual_selector')
        ->set('priceSelector', '.cost')
        ->set('manualCurrency', 'EUR')
        ->call('probeWithSelectors')
        ->assertSet('state', 'preview')
        ->assertSet('adapterKey', 'user-selector')
        ->assertSet('snapshot.price', '19.95')
        ->call('confirm')
        ->assertSet('state', 'idle');

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop)->not->toBeNull()
        ->and($shop->adapter_key)->toBe('user-selector')
        ->and($shop->price_selector)->toBe('.cost')
        ->and((string) $shop->current_price)->toBe('19.95');
});

test('manual selector that matches nothing surfaces inline error', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><body><div class="x">x</div></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'manual_selector')
        ->set('priceSelector', '.does-not-exist')
        ->call('probeWithSelectors')
        ->assertSet('state', 'manual_selector')
        ->assertSet('errorCode', 'user_selector_no_match');

    expect(Shop::query()->count())->toBe(0);
});

test('empty URL triggers empty_url error and does not fetch', function (): void {
    Http::fake();
    $product = Product::factory()->create();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', '   ')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'empty_url');

    Http::assertNothingSent();
});

test('ambiguous variants surfaces chooser and selecting a variant proceeds to preview', function (): void {
    $variantJson = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Family',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'productID' => '111-1',
                'url' => 'https://shop.example.com/p/1pack/',
                'offers' => ['@type' => 'Offer', 'price' => '23.95', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'productID' => '111-3',
                'url' => 'https://shop.example.com/p/3pack/',
                'offers' => ['@type' => 'Offer', 'price' => '52.86', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(
            withJsonLd($variantJson),
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'variant_chooser')
        ->assertSet('variants.0.title', 'Feliway 1-pack')
        ->assertSet('variants.1.price', '52.86')
        ->set('chosenVariantKey', '111-3')
        ->call('selectVariant')
        ->assertSet('state', 'preview')
        ->assertSet('snapshot.price', '52.86')
        ->call('confirm')
        ->assertSet('state', 'idle');

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop)->not->toBeNull()
        ->and($shop->variant_key)->toBe('111-3')
        ->and((string) $shop->current_price)->toBe('52.86');
});

test('confirm stores the pack size parsed from a non-authoritative title', function (): void {
    Http::fake(fakeJsonLdOffer('https://shop.example.com/p/1', '1.79', name: 'HiPRO Protein Drink Mango 300ml'));
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('snapshot.pack_size', null)
        ->assertSet('snapshot.pack_size_authoritative', false)
        ->call('confirm');

    $shop = Shop::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $shop->pack_quantity)->toBe('300.00')
        ->and($shop->pack_unit)->toBe('ml');
});

test('confirm stores no pack size when the title names none', function (): void {
    Http::fake(fakeJsonLdOffer());
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm');

    $shop = Shop::query()->where('product_id', $product->id)->firstOrFail();
    expect($shop->pack_quantity)->toBeNull()
        ->and($shop->pack_unit)->toBeNull()
        ->and($shop->unitPrice())->toBeNull()
        ->and($shop->unitPriceLabel())->toBeNull();
});

/**
 * Livewire re-hydrates `$product` from the request on every action without
 * authorizing it, so each public probe action authorizes the product itself.
 * Mounting is not enough: the caller can change between mount and action.
 */
test('every public probe action refuses a product the caller does not own', function (string $action): void {
    Http::fake();
    $product = Product::factory()->create(['currency' => 'EUR']);
    $component = Livewire::actingAs($product->user()->sole())
        ->test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->set('priceSelector', '.cost')
        ->set('chosenVariantKey', 'v1');

    $this->actingAs(Product::factory()->create()->user()->sole());

    $component->call($action)->assertForbidden();

    Http::assertNothingSent();
})->with(['probe', 'probeWithSelectors', 'selectVariant', 'showManualSelector']);

/**
 * `2 for 2.99` divides to 1.495, which `BundleOffer` rounds to 1.50 and the
 * preview used to truncate to 1.49. The comparison then failed and the regular
 * price beside the bundle price disappeared.
 */
test('the preview strikes through the regular price when the bundle total does not divide cleanly', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('state', 'preview')
        ->set('host', 'shop.example.com')
        ->set('snapshot', [
            'title' => 'Fizzy water',
            'price' => '1.50',
            'single_item_price' => '1.79',
            'bundle_quantity' => 2,
            'bundle_total_price' => '2.99',
            'currency' => 'EUR',
            'in_stock' => true,
        ])
        ->assertSee('€1.79')
        ->assertSee('Regular price');
});
