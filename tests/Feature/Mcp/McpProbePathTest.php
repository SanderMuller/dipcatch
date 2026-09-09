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

test('a page with no readable price is explained, not just refused', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><body>no price here</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1'])
        ->assertHasErrors()
        ->assertSee('no price could be read');
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
        null,
        (string) $probed->id,
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
        'https://shop.example.com/p/1' => Http::response(withJsonLd((string) json_encode([
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
    $body = withJsonLd((string) json_encode([
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
    $json = (string) json_encode([
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
