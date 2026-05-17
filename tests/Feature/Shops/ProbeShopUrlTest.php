<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function jsonLdPage(string $price = '50.00', string $currency = 'EUR', string $title = 'Test Item'): string
{
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:example.com');
    // Each test gets its own user so probe limiter doesn't bleed across tests.
});

test('success returns snapshot with normalized url + host + adapter key', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response(jsonLdPage('50.00', 'EUR'), 200, ['Content-Type' => 'text/html']),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1?utm_source=foo', $user);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->snapshot?->price)->toBe('50.00')
        ->and($outcome->snapshot?->currency)->toBe('EUR')
        ->and($outcome->host)->toBe('example.com')
        ->and($outcome->adapterKey)->toBe('jsonld')
        ->and($outcome->normalizedUrl)->toBe('https://example.com/p/1');
});

test('duplicate URL on same product returns DUPLICATE', function (): void {
    $product = Product::factory()->create();
    $existing = Shop::factory()->for($product)->create(['url' => 'https://example.com/p/1']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://www.example.com/p/1?utm_source=foo', $user);

    expect($outcome->isDuplicate())->toBeTrue()
        ->and($outcome->existingShop?->id)->toBe($existing->id);
});

test('robots.txt disallow → robots_disallowed failure', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
        'https://example.com/p/1' => Http::response(jsonLdPage(), 200),
    ]);

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);

    expect($outcome->isFailed())->toBeTrue()
        ->and($outcome->errorCode)->toBe('robots_disallowed');
});

test('Cloudflare-challenged 403 → blocked failure', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('<html>Just a moment...</html>', 403),
    ]);

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);

    expect($outcome->errorCode)->toBe('blocked');
});

test('extraction-failed when page has no parseable price', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('<html><body>no price here</body></html>', 200),
    ]);

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);

    expect($outcome->isFailed())->toBeTrue()
        ->and($outcome->errorCode)->toBe('no_adapter_matched');
});

test('currency mismatch is rejected with context', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response(jsonLdPage('50.00', 'GBP'), 200),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);

    expect($outcome->isFailed())->toBeTrue()
        ->and($outcome->errorCode)->toBe('currency_mismatch')
        ->and($outcome->context)->toBe(['expected' => 'EUR', 'actual' => 'GBP']);
});

test('invalid URL returns invalid_url failure (no fetch)', function (): void {
    Http::fake();

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'not a url', $user);

    expect($outcome->errorCode)->toBe('invalid_url');
    Http::assertNothingSent();
});

test('per-user rate limit kicks in after 6 probes in a minute', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/*' => Http::response(jsonLdPage(), 200),
    ]);

    $product = Product::factory()->create();
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $outcome = app(ProbeShopUrl::class)($product, "https://example.com/p/{$i}", $user);
        expect($outcome->isSuccess())->toBeTrue();
    }

    $blocked = app(ProbeShopUrl::class)($product, 'https://example.com/p/7', $user);
    expect($blocked->errorCode)->toBe('probe_rate_limited');
});

test('dedupe check runs before rate limit so retry on dup does not burn budget', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://example.com/p/1']);
    $user = User::factory()->create();

    foreach (range(1, 100) as $i) {
        $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);
        expect($outcome->isDuplicate())->toBeTrue();
    }
});
