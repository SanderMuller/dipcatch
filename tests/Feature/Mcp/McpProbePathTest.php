<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\DraftToken;
use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopFetcher\HostFetchMemory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The half of create_product and add_shop that runs before a draft exists.
 * Every other MCP test mints a DraftToken directly, so nothing exercised the
 * probe, the preview, or how a failure is explained back to the assistant.
 */
beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');

    // No stray requests: a regression that reordered the ownership check
    // ahead of the probe would otherwise fetch a real shop in CI.
    Http::preventStrayRequests();
});

test('the first call previews the page and writes nothing', function (): void {
    Http::fake(fakeJsonLdOffer());

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertOk()
        ->assertSee('Demo Item')
        ->assertSee('50.00')
        ->assertSee('draft');

    expect($me->products()->count())->toBe(0);
});

test('a draft issued from a real probe confirms into exactly one product', function (): void {
    // Drives the real chain — ProbeShopUrl, ShopDraft::flatten, DraftToken —
    // rather than hand-building a snapshot, so a change to any link fails.
    Http::fake(fakeJsonLdOffer());

    $me = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://shop.example.com/p/1', $me);

    expect($outcome->isSuccess())->toBeTrue();

    $token = DraftToken::issue(
        $me,
        ShopDraft::flatten($outcome),
        (string) $outcome->normalizedUrl,
        (string) $outcome->adapterKey,
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $token, 'confirm' => true])
        ->assertOk()
        ->assertSee('Demo Item');

    expect($me->products()->count())->toBe(1)
        ->and((string) $me->products()->first()?->shops()->first()?->current_price)->toBe('50.00');
});

test('a create_product draft sent without confirm is told to confirm, not that its url is bad', function (): void {
    // Both rules used to key on each other rather than on `confirm`: a draft
    // with no `confirm` left `url` unrequired, and the preview branch probed
    // the empty string it got instead.
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['draft' => 'a-token'])
        ->assertHasErrors()
        ->assertSee('creates the product only with confirm: true')
        ->assertDontSee('does not look like a URL')
        ->assertDontSee('Pass a url');
});

test('an add_shop draft sent without confirm is told to confirm, not that its url is bad', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'draft' => 'a-token'])
        ->assertHasErrors()
        ->assertSee('adds the shop only with confirm: true')
        ->assertDontSee('does not look like a URL')
        ->assertDontSee('Pass a url');
});

test('a stale draft alongside a new url is refused rather than quietly ignored', function (): void {
    // The old rules validated this and previewed the url, dropping the draft
    // without saying so.
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1', 'draft' => 'a-token'])
        ->assertHasErrors()
        ->assertSee('creates the product only with confirm: true');
});

test('create_product called with no arguments asks for a url', function (): void {
    // The only assertion of the `url` rule itself: drop it, or key it back on
    // `draft`, and this call probes the empty string instead.
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class)
        ->assertHasErrors()
        ->assertSee('Pass a url to preview a product page.')
        ->assertDontSee('does not look like a URL');
});

test('add_shop called with only a product asks for a url', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id])
        ->assertHasErrors()
        ->assertSee('Pass a url to preview a product page.')
        ->assertDontSee('does not look like a URL');
});

test('confirm: 1 is refused rather than read as a preview', function (): void {
    // `boolean` without `strict` accepts 1, which then fails the handler's
    // `=== true` and falls into the preview branch — the same empty-URL probe,
    // reached through a value the caller meant as a confirmation.
    Http::fake(fakeJsonLdOffer());

    $me = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://shop.example.com/p/1', $me);

    $token = DraftToken::issue(
        $me,
        ShopDraft::flatten($outcome),
        (string) $outcome->normalizedUrl,
        (string) $outcome->adapterKey,
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $token, 'confirm' => 1])
        ->assertHasErrors()
        ->assertSee('confirm takes a JSON boolean')
        ->assertDontSee('does not look like a URL');

    expect($me->products()->count())->toBe(0);
});

test('an add_shop confirm: 1 is refused rather than read as a preview', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'draft' => 'a-token', 'confirm' => 1])
        ->assertHasErrors()
        ->assertSee('confirm takes a JSON boolean')
        ->assertDontSee('does not look like a URL');

    expect($product->shops()->count())->toBe(0);
});

test('confirm takes only a real boolean', function (): void {
    // `boolean:strict` is what keeps the rules and the `=== true` branch in
    // one domain. "true" was always refused; 0 and "1" were not.
    foreach (['true', 0, '1'] as $confirm) {
        DipCatchServer::actingAs(User::factory()->create())
            ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1', 'confirm' => $confirm])
            ->assertHasErrors()
            ->assertSee('confirm takes a JSON boolean');
    }
});

test('confirming with no draft asks for the draft alone, not for a url as well', function (): void {
    // `assertDontSee('url')` rather than the message text: a `url` rule keyed
    // back on `draft` fires its own default message here, which names the
    // field but not the phrase.
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['confirm' => true])
        ->assertHasErrors()
        ->assertSee('Pass the draft from the preview call')
        ->assertDontSee('url');
});

test('an add_shop confirm with no draft asks for the draft alone', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('Pass the draft from the preview call')
        ->assertDontSee('url');
});

test('a page with no readable price is explained, not just refused', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><body>no price here</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('no price could be read')
        // Named as a class of cause. Blaming JavaScript alone sent a reader
        // after the wrong thing on a shop whose price is in the server HTML and
        // simply split across four elements.
        ->assertSee('splits it across elements')
        ->assertDontSee('which DipCatch cannot see');
});

test('a malformed url is explained in words', function (): void {
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'not-a-url'])
        ->assertHasErrors()
        ->assertSee('does not look like a URL');
});

test('a robots-disallowed page says so rather than failing vaguely', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
    ]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('honours');
});

test('add_shop reports a url already tracked on that product instead of writing', function (): void {
    Http::fake(fakeJsonLdOffer());

    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, [
            'product_id' => (string) $product->id,
            'url' => 'https://shop.example.com/p/1',
        ])
        ->assertHasErrors()
        ->assertSee('already tracked');

    expect($product->shops()->count())->toBe(1);
});

test('an add_shop draft cannot be confirmed onto a different product', function (): void {
    // The probe checks currency against the product it was given, so a draft
    // spent elsewhere would write a shop that product's guard would refuse.
    Http::fake(fakeJsonLdOffer());

    $me = User::factory()->create();
    $probed = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);
    $other = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    $outcome = app(ProbeShopUrl::class)($probed, 'https://shop.example.com/p/1', $me);

    $token = DraftToken::issue(
        $me,
        ShopDraft::flatten($outcome),
        (string) $outcome->normalizedUrl,
        (string) $outcome->adapterKey,
        variantKey: null,
        productId: (string) $probed->id,
    );

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, [
            'product_id' => (string) $other->id,
            'draft' => $token,
            'confirm' => true,
        ])
        ->assertHasErrors();

    expect($other->shops()->count())->toBe(0);

    // ...and it does work on the product it was probed against.
    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, [
            'product_id' => (string) $probed->id,
            'draft' => $token,
            'confirm' => true,
        ])
        ->assertOk();

    expect($probed->shops()->count())->toBe(1);
});

test('a damaged draft token is not reported as expired', function (): void {
    $me = User::factory()->create();

    $token = DraftToken::issue(
        $me,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://shop.example.com/p/1',
        'jsonld',
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => substr($token, 0, -4) . 'zzzz', 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('could not be read');

    expect($me->products()->count())->toBe(0);
});

test('an expired draft says so', function (): void {
    $me = User::factory()->create();

    $token = DraftToken::issue(
        $me,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://shop.example.com/p/1',
        'jsonld',
        variantKey: null,
    );

    $this->travel(16)->minutes();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $token, 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('has expired');

    expect($me->products()->count())->toBe(0);
});

test('a draft prepared for another product is refused as the wrong product', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    $other = Product::factory()->create(['user_id' => $me->id]);

    $token = DraftToken::issue(
        $me,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://shop.example.com/p/1',
        'jsonld',
        variantKey: null,
        productId: (string) $other->id,
    );

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'draft' => $token, 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('different product');

    expect($product->shops()->count())->toBe(0);
});

test('a draft issued to another account is refused as theirs', function (): void {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $token = DraftToken::issue(
        $them,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://shop.example.com/p/1',
        'jsonld',
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $token, 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('another account');

    expect($me->products()->count())->toBe(0);
});

test('a shop that keeps refusing is not described as worth retrying', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/*' => Http::response('nope', 403),
    ]);

    $me = User::factory()->create();

    foreach (range(1, HostFetchMemory::PERSISTENT_AFTER) as $i) {
        RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');

        DipCatchServer::actingAs($me)
            ->tool(CreateProductTool::class, ['url' => "https://shop.example.com/p/{$i}"])
            ->assertHasErrors();
    }

    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/again'])
        ->assertHasErrors()
        ->assertSee('Retrying will not help');
});

test('a first refusal still reads as a single block', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('nope', 403),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('That shop blocked the request.');
});

test('an unreadable stock state is stored as unknown, not as available', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'Sanimed Skin Sensitive Kat',
            'offers' => [
                '@type' => 'Offer',
                'price' => '19.95',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/LimitedAvailability',
            ],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertOk()
        ->assertSee('unknown')
        ->assertSee('LimitedAvailability');
});

test('a page whose words say it is unavailable is stored as out of stock', function (): void {
    $body = withJsonLd(json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Sanimed Skin Sensitive Kat',
        'offers' => ['@type' => 'Offer', 'price' => '19.95', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR)) . '<p>Tijdelijk niet leverbaar</p>';

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response($body, 200, ['Content-Type' => 'text/html']),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertOk()
        ->assertSee('out_of_stock')
        ->assertSee('tijdelijk niet leverbaar');
});

test('the variant chooser says what each key is and what it costs', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Sanimed Skin Sensitive Cat',
        'url' => 'https://shop.example.com/p/1',
        'offers' => [
            ['@type' => 'Offer', 'sku' => 'MP32275', 'name' => '24 x 100 g', 'price' => '41.65', 'priceCurrency' => 'EUR'],
            ['@type' => 'Offer', 'sku' => 'MP4838', 'name' => '12 x 100 g', 'price' => '21.25', 'priceCurrency' => 'EUR'],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('MP4838')
        ->assertSee('12 x 100 g')
        ->assertSee('21.25');
});

test('a second refusal says how many there have been', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/*' => Http::response('nope', 403),
    ]);

    $me = User::factory()->create();

    foreach (['1', '2'] as $i) {
        RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');

        $response = DipCatchServer::actingAs($me)
            ->tool(CreateProductTool::class, ['url' => "https://shop.example.com/p/{$i}"]);

        $response->assertHasErrors();

        if ($i === '2') {
            $response->assertSee('That is 2 in a row.');
        }
    }
});

test('a 429 with no Retry-After does not invent a retry interval', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><head><title>429 Too Many Requests</title></head></html>', 429),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('It did not say for how long')
        ->assertDontSee('60 seconds');
});

test('a 429 that states an interval quotes the shop', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('slow down', 429, ['Retry-After' => '120']),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('It asks for 120 seconds.');
});

test('the preview says how many variants the page sells', function (): void {
    // The caller could not tell a single-variant page from one of several
    // silently picked, and fetched the shop's own JSON to find out.
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Creapure Creatine',
        'hasVariant' => [[
            '@type' => 'Product',
            'name' => 'Creapure Creatine - Natural (Unflavoured) / 500g',
            'sku' => '1089215',
            'offers' => ['@type' => 'Offer', 'price' => '29.99', 'priceCurrency' => 'EUR'],
        ]],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ]);

    $me = User::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertSee('This page sells one variant.');
});

test('a page DipCatch cannot read can be kept as a link', function (): void {
    // The research that used to be discarded: a URL verified as the right
    // product at the right size, at a shop that refuses to be read.
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create(['currency' => 'EUR']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, [
            'product_id' => (string) $product->id,
            'url' => 'https://www.bol.com/nl/nl/p/thing/9200000000000001/',
            'keep_as_link' => true,
        ])
        ->assertHasNoErrors()
        ->assertSee('reference');

    $shop = $product->refresh()->shops->sole();

    expect($shop->isReference())->toBeTrue()
        ->and($shop->current_price)->toBeNull();

    // No fetch, so nothing was spent from the page budget — which is what
    // makes it possible to keep a page add_shop has just refused to read.
    Http::assertNothingSent();
});

test('keeping a link twice says so instead of adding it twice', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create(['currency' => 'EUR']);
    $url = 'https://www.bol.com/nl/nl/p/thing/9200000000000001/';

    $call = fn (): object => DipCatchServer::actingAs($me)->tool(AddShopTool::class, [
        'product_id' => (string) $product->id,
        'url' => $url,
        'keep_as_link' => true,
    ]);

    $call()->assertHasNoErrors();
    $call()->assertHasErrors()->assertSee('already kept as a link');

    expect($product->refresh()->shops)->toHaveCount(1);
});

test('a url already tracked cannot be downgraded to a link', function (): void {
    // The price is being read. Keeping it as a link would throw that away.
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1']);

    DipCatchServer::actingAs($me)
        ->tool(AddShopTool::class, [
            'product_id' => (string) $product->id,
            'url' => 'https://shop.example.com/p/1',
            'keep_as_link' => true,
        ])
        ->assertHasErrors()
        ->assertSee('already tracked on this product');
});
