<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\ShopFetcher\ShopFetcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * @param  array<string, string>  $hostPrices  host => decimal price string
 * @return array<string, PromiseInterface>
 */
function fakeJsonLdForHosts(array $hostPrices): array
{
    $fakes = [];
    foreach ($hostPrices as $host => $price) {
        $json = json_encode([
            '@type' => 'Product',
            'name' => "Demo @ {$host}",
            'offers' => [
                '@type' => 'Offer',
                'price' => $price,
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_THROW_ON_ERROR);

        $fakes["https://{$host}/robots.txt"] = Http::response('', 404);
        $fakes["https://{$host}/p/1"] = Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']);
    }

    return $fakes;
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop-a.test');
    RateLimiter::clear('dipcatch:fetcher:host:shop-b.test');
});

/*
|--------------------------------------------------------------------------
| Two-shop interleave: order independence under simultaneous recomputes
|--------------------------------------------------------------------------
|
| Both orderings (A-then-B, B-then-A) must yield the same final state for
| Product.cheapest_shop_id, cheapest_price, and the ProductCheapestHistory
| segment chain. Each ordering also asserts the single-open-segment
| invariant the lockForUpdate in Product::recomputeCheapestShop protects.
*/
dataset('orderings', [
    'A then B' => [['shop_a', 'shop_b']],
    'B then A' => [['shop_b', 'shop_a']],
]);

test('two CheckShopPrice runs against shops on the same product settle deterministically', function (array $order): void {
    Http::fake(fakeJsonLdForHosts([
        'shop-a.test' => '60.00',
        'shop-b.test' => '45.00',
    ]));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shops = [
        'shop_a' => Shop::factory()->for($product)->create([
            'url' => 'https://shop-a.test/p/1',
            'current_price' => '90.00',
            'current_in_stock' => true,
        ]),
        'shop_b' => Shop::factory()->for($product)->create([
            'url' => 'https://shop-b.test/p/1',
            'current_price' => '100.00',
            'current_in_stock' => true,
        ]),
    ];

    foreach ($order as $key) {
        assert(is_string($key) && isset($shops[$key]));
        new CheckShopPrice($shops[$key])->handle(
            app(ShopFetcher::class),
            app(AdapterResolver::class),
        );
    }

    $product->refresh();

    expect($product->cheapest_shop_id)->toBe($shops['shop_b']->id)
        ->and((string) $product->cheapest_price)->toBe('45.00');

    // Each successful CheckShopPrice writes a price_check row.
    expect(PriceCheck::query()->where('shop_id', $shops['shop_a']->id)->count())->toBe(1)
        ->and(PriceCheck::query()->where('shop_id', $shops['shop_b']->id)->count())->toBe(1);

    // History invariant: exactly one open segment per product, no matter how
    // many recomputes ran. Old segments must have ended_at set.
    expect(ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->whereNull('ended_at')
        ->count())->toBe(1);
    expect(ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->whereNotNull('ended_at')
        ->whereColumn('ended_at', '<', 'started_at')
        ->count())->toBe(0);

    /** @var ProductCheapestHistory $open */
    $open = ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->whereNull('ended_at')
        ->first();
    expect($open->cheapest_shop_id)->toBe($shops['shop_b']->id)
        ->and((string) $open->cheapest_price)->toBe('45.00');
})->with('orderings');

test('rerunning CheckShopPrice for the same shop with unchanged price does not write a new history segment', function (): void {
    Http::fake(fakeJsonLdForHosts(['shop-a.test' => '60.00']));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop-a.test/p/1',
        'current_price' => '90.00',
    ]);

    // First run: 60.00 becomes the cheapest, creating segment #1.
    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class));
    $segmentsAfterFirst = ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->count();

    // Second run with identical upstream price: idempotent — no new segment.
    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class));

    expect(ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->count())->toBe($segmentsAfterFirst)
        ->and(ProductCheapestHistory::query()
            ->where('product_id', $product->id)
            ->whereNull('ended_at')
            ->count())->toBe(1);
    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(2);
});

test('a failing recheck on the current cheapest does not corrupt the history segment', function (): void {
    // shop-a is the active cheapest; shop-b is more expensive. shop-a now
    // returns a parse-failure page. The failing check must NOT close the
    // open history segment because shop-a is still the cheapest in-stock
    // option (the persist path skips recompute for non-Ok statuses only via
    // the shop-state update; but recompute itself still runs unconditionally
    // — verify the segment chain stays consistent regardless).
    Http::fake([
        'https://shop-a.test/robots.txt' => Http::response('', 404),
        'https://shop-a.test/p/1' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shopA = Shop::factory()->for($product)->create([
        'url' => 'https://shop-a.test/p/1',
        'current_price' => '60.00',
        'current_in_stock' => true,
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://shop-b.test/p/1',
        'current_price' => '90.00',
        'current_in_stock' => true,
    ]);

    // Seed the open segment so we can verify it's preserved.
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shopA->id,
        'cheapest_price' => '60.00',
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);
    $product->forceFill([
        'cheapest_shop_id' => $shopA->id,
        'cheapest_price' => '60.00',
    ])->save();

    new CheckShopPrice($shopA)->handle(app(ShopFetcher::class), app(AdapterResolver::class));

    $product->refresh();

    // shop-a's current_price was NOT overwritten (failed status), so it's
    // still the cheapest. The parse failure ticks the counter but doesn't
    // change the cheapest assignment.
    expect($product->cheapest_shop_id)->toBe($shopA->id)
        ->and((string) $product->cheapest_price)->toBe('60.00')
        ->and($shopA->fresh()->last_status)->toBe(ScrapeStatus::ParseError)
        ->and($shopA->fresh()->consecutive_failures)->toBe(1);

    // History invariant intact: still exactly one open segment.
    expect(ProductCheapestHistory::query()
        ->where('product_id', $product->id)
        ->whereNull('ended_at')
        ->count())->toBe(1);
});
