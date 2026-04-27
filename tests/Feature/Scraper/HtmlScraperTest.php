<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Services\Scraper\ScrapeRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScraperFixtures;

beforeEach(function (): void {
    config()->set('scraper.user_agent', 'TestBot/1.0');
    config()->set('scraper.timeout', 5);
    config()->set('scraper.max_redirects', 5);
    config()->set('scraper.host.min_interval_seconds', 8);
    config()->set('scraper.host.jitter_seconds', 0);
    config()->set('scraper.host.lock_ttl_seconds', 30);
    config()->set('scraper.robots.cache_ttl_seconds', 3600);
    Cache::flush();
});

test('scrapes a microdata-style page (selector + meta currency + OG image)', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/wireless-headphones',
        priceSelector: '.product-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('1299.95')
        ->and($result->currency)->toBe('EUR')
        ->and($result->title)->toBe('Acme Wireless Headphones')
        ->and($result->imageUrl)->toBe('https://shop.example.com/images/headphones.jpg');
});

test('scrapes a page where only OG meta is available for title + image', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/speaker' => Http::response(ScraperFixtures::load('og_only.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/speaker',
        priceSelector: '.price',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('249.00')
        ->and($result->currency)->toBe('USD')
        ->and($result->title)->toBe('Bluetooth Speaker (OG)')
        ->and($result->imageUrl)->toBe('https://cdn.example.com/speaker.png');
});

test('scrapes from JSON-LD when no selector matches, after exhausting fallbacks', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/camera' => Http::response(ScraperFixtures::load('json_ld.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/camera',
        priceSelector: '.does-not-exist',
        fallbackSelectors: ['.also-missing'],
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('899.00')
        ->and($result->currency)->toBe('GBP');
});

test('returns NeedsJs when nothing yields a price', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://spa.example.com/product' => Http::response(ScraperFixtures::load('needs_js.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://spa.example.com/product',
        priceSelector: '.price',
    ));

    expect($result->status)->toBe(ScrapeStatus::NeedsJs)
        ->and($result->price)->toBeNull();
});

test('multi-currency page picks the preferred currency', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/dual' => Http::response(ScraperFixtures::load('multi_currency.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/dual',
        priceSelector: '.price',
        preferredCurrency: 'USD',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->currency)->toBe('USD');
});

test('returns HttpError on non-2xx response', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/missing' => Http::response('Not found', 404),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/missing',
        priceSelector: '.price',
    ));

    expect($result->status)->toBe(ScrapeStatus::HttpError)
        ->and($result->error)->toContain('404');
});

test('uses primary fallback selectors before falling back to JSON-LD', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/wireless-headphones',
        priceSelector: '.does-not-match',
        fallbackSelectors: ['.product-price'],
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('1299.95');
});
