<?php declare(strict_types=1);

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Enums\ProbeFailure;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

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
        ->and($outcome->errorCode)->toBe(ProbeFailure::RobotsDisallowed);
});

test('Cloudflare-challenged 403 → blocked failure', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('<html>Just a moment...</html>', 403),
    ]);

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/1', $user);

    expect($outcome->errorCode)->toBe(ProbeFailure::Blocked);
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
        ->and($outcome->errorCode)->toBe(ProbeFailure::ExtractionFailed)
        ->and($outcome->extractionReason)->toBe('no_adapter_matched');
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
        ->and($outcome->errorCode)->toBe(ProbeFailure::CurrencyMismatch)
        ->and($outcome->context)->toBe(['expected' => 'EUR', 'actual' => 'GBP']);
});

test('invalid URL returns invalid_url failure (no fetch)', function (): void {
    Http::fake();

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'not a url', $user);

    expect($outcome->errorCode)->toBe(ProbeFailure::InvalidUrl);
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
    expect($blocked->errorCode)->toBe(ProbeFailure::ProbeRateLimited);
});

test('multi-variant ProductGroup with no URL match returns AMBIGUOUS with variants', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Family',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'productID' => '111-1',
                'url' => 'https://example.com/p/1pack/',
                'offers' => ['@type' => 'Offer', 'price' => '23.95', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'productID' => '111-3',
                'url' => 'https://example.com/p/3pack/',
                'offers' => ['@type' => 'Offer', 'price' => '52.86', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $html = withJsonLd($json);

    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/canonical' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://example.com/p/canonical', $user);

    expect($outcome->isAmbiguous())->toBeTrue()
        ->and($outcome->host)->toBe('example.com')
        ->and($outcome->normalizedUrl)->toBe('https://example.com/p/canonical')
        ->and($outcome->variants)->toHaveCount(2)
        ->and($outcome->variants[0]->key)->toBe('111-1')
        ->and($outcome->variants[0]->price)->toBe('23.95')
        ->and($outcome->variants[1]->key)->toBe('111-3');
});

test('passing variantKey resolves AMBIGUOUS into SUCCESS', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Family',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'productID' => '111-1',
                'url' => 'https://example.com/p/1pack/',
                'offers' => ['@type' => 'Offer', 'price' => '23.95', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'productID' => '111-3',
                'url' => 'https://example.com/p/3pack/',
                'offers' => ['@type' => 'Offer', 'price' => '52.86', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $html = withJsonLd($json);

    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/canonical' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(
        $product,
        'https://example.com/p/canonical',
        $user,
        variantKey: '111-3',
    );

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->snapshot?->price)->toBe('52.86')
        ->and($outcome->snapshot?->title)->toBe('Feliway 3-pack');
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

test('ProbeOutcome::failed rejects extractionReason on non-ExtractionFailed codes', function (): void {
    expect(fn () => ProbeOutcome::failed(
        errorCode: ProbeFailure::HttpError,
        extractionReason: 'jsonld_no_price',
    ))->toThrow(
        InvalidArgumentException::class,
        'extractionReason is only valid when errorCode === ProbeFailure::ExtractionFailed',
    );
});
