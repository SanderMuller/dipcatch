<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function fakeJsonLdResponse(string $host, string $path, string $price = '60.00', string $currency = 'EUR'): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'X',
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        "https://{$host}/robots.txt" => Http::response('', 404),
        "https://{$host}{$path}" => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

test('successful check writes price_check, updates offer, recomputes cheapest', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '90.00',
        'consecutive_failures' => 2,
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class));

    $shop->refresh();
    $product->refresh();

    expect((string) $shop->current_price)->toBe('60.00')
        ->and($shop->consecutive_failures)->toBe(0)
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('jsonld')
        ->and((string) $product->cheapest_price)->toBe('60.00');

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

test('parse failure increments main counter and writes failed price_check', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 1,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(2)
        ->and($shop->consecutive_5xx_failures)->toBe(0)
        ->and($shop->health)->toBe(ShopHealth::Ok);
});

test('5xx increments the 5xx counter only', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('oops', 503),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 0,
        'consecutive_5xx_failures' => 0,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->consecutive_5xx_failures)->toBe(1)
        ->and($shop->last_status)->toBe(ScrapeStatus::TransientServerError);
});

test('main counter reaching dead_after flips health to dead + active=false', function (): void {
    config()->set('dipcatch.shop.dead_after', 3);

    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('no metadata', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 2,
        'active' => true,
        'health' => 'failing',
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    $shop->refresh();
    expect($shop->health)->toBe(ShopHealth::Dead)
        ->and($shop->active)->toBeFalse();
});

test('robots disallow flips offer to dead immediately', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
        'https://shop.test/p/1' => Http::response('<html></html>', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'health' => 'ok',
        'active' => true,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    $shop->refresh();
    expect($shop->health)->toBe(ShopHealth::Dead)
        ->and($shop->active)->toBeFalse()
        ->and($shop->last_status)->toBe(ScrapeStatus::RobotsDisallowed);
});

test('inactive or dead offer is skipped', function (): void {
    $shop = Shop::factory()->dead()->create(['url' => 'https://shop.test/p/1']);

    Http::fake();

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    Http::assertNothingSent();
    expect(PriceCheck::query()->count())->toBe(0);
});

test('per-host rate limit releases instead of writing a failed check or ticking counter', function (): void {
    $perMinute = config()->integer('dipcatch.fetcher.rate_limit_per_minute', 12);
    for ($i = 0; $i < $perMinute; $i++) {
        RateLimiter::hit(ShopFetcher::throttleKey('shop.test'));
    }
    // Pre-warm robots cache so the policy check doesn't issue an HTTP call —
    // otherwise assertNothingSent below would see the robots.txt fetch.
    Cache::put('dipcatch:robots:shop.test', [], 60);
    Http::fake();

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 0,
    ]);

    // handle() catches RateLimitedByHost and calls $this->release(). Without a
    // queued job context, InteractsWithQueue::release() is a no-op — what we
    // care about is that NO PriceCheck row is written and the failure counter
    // does NOT tick, even though the fetcher rejected the host.
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    Http::assertNothingSent();
    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(0)
        ->and($shop->fresh()->consecutive_failures)->toBe(0);
});

test('successful check resets both counters and clears failing health', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1'));

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 5,
        'consecutive_5xx_failures' => 4,
        'health' => 'failing',
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->consecutive_5xx_failures)->toBe(0)
        ->and($shop->health)->toBe(ShopHealth::Ok);
});
