<?php declare(strict_types=1);

use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Cache::flush();
    // The named limiter is keyed by host; clear any leftover hits.
    RateLimiter::clear('dipcatch:fetcher:host:example.com');
    RateLimiter::clear('dipcatch:fetcher:host:blocked.com');
    RateLimiter::clear('dipcatch:fetcher:host:slow.com');
});

test('robots.txt 404 → fail-open, fetches the page', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(ShopFetcher::class)->fetch('https://example.com/p/1');

    expect($result->html)->toContain('ok')
        ->and($result->host)->toBe('example.com')
        ->and($result->statusCode)->toBe(200);
});

test('robots.txt explicitly disallows our UA → RobotsDisallowed', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /p/", 200),
        'https://example.com/p/1' => Http::response('<html>nope</html>', 200),
    ]);

    expect(fn () => app(ShopFetcher::class)->fetch('https://example.com/p/1'))
        ->toThrow(RobotsDisallowed::class);
});

test('robots.txt allows different path but disallows ours', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /private/", 200),
        'https://example.com/p/1' => Http::response('<html>ok</html>', 200),
    ]);

    $result = app(ShopFetcher::class)->fetch('https://example.com/p/1');

    expect($result->statusCode)->toBe(200);
});

test('Cloudflare challenge body on 403 → Blocked', function (): void {
    Http::fake([
        'https://blocked.com/robots.txt' => Http::response('', 404),
        'https://blocked.com/p/1' => Http::response('<html>Just a moment...</html>', 403),
    ]);

    expect(fn () => app(ShopFetcher::class)->fetch('https://blocked.com/p/1'))
        ->toThrow(Blocked::class);
});

test('401 → Blocked', function (): void {
    Http::fake([
        'https://blocked.com/robots.txt' => Http::response('', 404),
        'https://blocked.com/p/1' => Http::response('nope', 401),
    ]);

    expect(fn () => app(ShopFetcher::class)->fetch('https://blocked.com/p/1'))
        ->toThrow(Blocked::class);
});

test('429 → RateLimitedByHost with Retry-After', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('slow down', 429, ['Retry-After' => '120']),
    ]);

    try {
        app(ShopFetcher::class)->fetch('https://example.com/p/1');
        $this->fail('expected RateLimitedByHost');
    } catch (RateLimitedByHost $e) {
        expect($e->retryAfterSeconds)->toBe(120);
    }
});

test('5xx → TemporaryFailure', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('oops', 503),
    ]);

    expect(fn () => app(ShopFetcher::class)->fetch('https://example.com/p/1'))
        ->toThrow(TemporaryFailure::class);
});

test('404 → HttpError', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('not found', 404),
    ]);

    expect(fn () => app(ShopFetcher::class)->fetch('https://example.com/p/1'))
        ->toThrow(HttpError::class);
});

test('per-host rate limit kicks in after N requests in a minute', function (): void {
    config()->set('dipcatch.fetcher.rate_limit_per_minute', 2);

    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response('<html>ok</html>', 200),
    ]);

    app(ShopFetcher::class)->fetch('https://example.com/p/1');
    app(ShopFetcher::class)->fetch('https://example.com/p/1');

    expect(fn () => app(ShopFetcher::class)->fetch('https://example.com/p/1'))
        ->toThrow(RateLimitedByHost::class);
});

test('host normalization strips www.', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://www.example.com/p/1' => Http::response('<html>ok</html>', 200),
    ]);

    $result = app(ShopFetcher::class)->fetch('https://www.example.com/p/1');

    expect($result->host)->toBe('example.com');
});

test('response larger than body_cap_bytes throws HttpError(413)', function (): void {
    config()->set('dipcatch.fetcher.body_cap_bytes', 1024);
    $bigBody = '<html>' . str_repeat('x', 4096) . '</html>';
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
        'https://example.com/p/1' => Http::response($bigBody, 200, ['Content-Type' => 'text/html']),
    ]);

    try {
        app(ShopFetcher::class)->fetch('https://example.com/p/1');
        $this->fail('Expected HttpError(413), got nothing.');
    } catch (HttpError $e) {
        expect($e->statusCode)->toBe(413);
    }
});
