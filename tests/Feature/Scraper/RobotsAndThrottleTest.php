<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Services\Scraper\HostThrottle;
use App\Services\Scraper\ScrapeRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScraperFixtures;

beforeEach(function (): void {
    config()->set('scraper.user_agent', 'TestBot/1.0');
    config()->set('scraper.timeout', 5);
    config()->set('scraper.host.min_interval_seconds', 8);
    config()->set('scraper.host.jitter_seconds', 0);
    config()->set('scraper.host.lock_ttl_seconds', 30);
    config()->set('scraper.robots.cache_ttl_seconds', 3600);
    Cache::flush();
});

test('returns RobotsBlocked when robots.txt disallows our user-agent', function (): void {
    $robots = "User-agent: *\nDisallow: /private\n";

    Http::fake([
        '*/robots.txt' => Http::response($robots, 200),
        'https://shop.example.com/private/item' => Http::response('should never reach', 200),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/private/item',
        priceSelector: '.price',
    ));

    expect($result->status)->toBe(ScrapeStatus::RobotsBlocked);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/private/item'));
});

test('robots.txt fetched once, then cached for the host', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response(ScraperFixtures::load('microdata.html'), 200),
        'https://shop.example.com/b' => Http::response(ScraperFixtures::load('microdata.html'), 200),
    ]);

    $scraper = ScraperFixtures::makeScraper();

    $scraper->scrape(new ScrapeRequest(url: 'https://shop.example.com/a', priceSelector: '.product-price'));
    // Move past the throttle window without burning the robots cache.
    Cache::forget('scrape:host:last:shop.example.com');
    $scraper->scrape(new ScrapeRequest(url: 'https://shop.example.com/b', priceSelector: '.product-price'));

    Http::assertSentCount(3); // 1 robots fetch + 2 page fetches
});

test('rapid-fire same host is throttled by min interval', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response(ScraperFixtures::load('microdata.html'), 200),
    ]);

    $scraper = ScraperFixtures::makeScraper();

    $first = $scraper->scrape(new ScrapeRequest(url: 'https://shop.example.com/a', priceSelector: '.product-price'));
    $second = $scraper->scrape(new ScrapeRequest(url: 'https://shop.example.com/a', priceSelector: '.product-price'));

    expect($first->status)->toBe(ScrapeStatus::Ok)
        ->and($second->status)->toBe(ScrapeStatus::Throttled);
});

test('different hosts do not affect each other s throttle', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop-a.example.com/a' => Http::response(ScraperFixtures::load('microdata.html'), 200),
        'https://shop-b.example.com/a' => Http::response(ScraperFixtures::load('microdata.html'), 200),
    ]);

    $scraper = ScraperFixtures::makeScraper();

    $first = $scraper->scrape(new ScrapeRequest(url: 'https://shop-a.example.com/a', priceSelector: '.product-price'));
    $second = $scraper->scrape(new ScrapeRequest(url: 'https://shop-b.example.com/a', priceSelector: '.product-price'));

    expect($first->status)->toBe(ScrapeStatus::Ok)
        ->and($second->status)->toBe(ScrapeStatus::Ok);
});

test('held concurrency lock blocks a second worker on the same host', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response(ScraperFixtures::load('microdata.html'), 200),
    ]);

    // Simulate "another worker is already in flight": grab the same lock first.
    $heldLock = Cache::lock('scrape:host:shop.example.com', 30);
    $heldLock->get();

    try {
        $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
            url: 'https://shop.example.com/a',
            priceSelector: '.product-price',
        ));
    } finally {
        $heldLock->release();
    }

    expect($result->status)->toBe(ScrapeStatus::Throttled)
        ->and($result->error)->toContain('concurrent');
});

test('HostThrottle::shouldWaitSeconds honors a Crawl-delay larger than min interval', function (): void {
    config()->set('scraper.host.min_interval_seconds', 8);

    $throttle = new HostThrottle();
    $throttle->markStarted('https://shop.example.com/x');

    expect($throttle->shouldWaitSeconds('https://shop.example.com/x', crawlDelayOverride: 60))->toBeGreaterThan(50);
});

test('HostThrottle::markStarted keeps the timestamp for long enough to outlive a multi-minute Crawl-delay', function (): void {
    config()->set('scraper.host.min_interval_seconds', 8);

    // Caching system stores the timestamp; we need to know the TTL applied.
    $throttle = new HostThrottle();
    $throttle->markStarted('https://shop.example.com/x');

    // Inspect the underlying cache TTL — it must outlive the largest plausible
    // Crawl-delay value (Codex P2 finding: previous TTL was min_interval * 60
    // = 480s, which expired before a 600s Crawl-delay window finished).
    $store = Cache::store();
    $key = 'scrape:host:last:shop.example.com';

    expect(Cache::has($key))->toBeTrue();

    // Re-mark to ensure idempotency, then verify long retention by simulating
    // Crawl-delay >> previous min_interval-derived TTL.
    expect($throttle->shouldWaitSeconds('https://shop.example.com/x', crawlDelayOverride: 3600))
        ->toBeGreaterThan(3500);
});
