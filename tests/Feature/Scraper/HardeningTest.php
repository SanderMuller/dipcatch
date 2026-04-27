<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Services\Scraper\ScrapeRequest;
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

test('retries a transient connection error and succeeds on the next attempt', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::sequence()
            ->pushFailedConnection('connect failed')
            ->push(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.product-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('1299.95');
});

test('does not retry a 4xx response', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response('Not found', 404),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.product-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::HttpError)
        ->and($result->error)->toContain('404');
    Http::assertSentCount(2); // robots + page (not retried)
});

test('rejects a response whose Content-Length announces > 5MB', function (): void {
    $hugeAnnounced = (string) (6 * 1024 * 1024);

    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response('tiny body', 200, ['Content-Length' => $hugeAnnounced]),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.product-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::HttpError)
        ->and($result->error)->toContain('too large');
});

test('rejects a response whose body actually exceeds 5MB', function (): void {
    $body = str_repeat('a', (5 * 1024 * 1024) + 1);

    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response($body, 200), // no Content-Length header
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.product-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::HttpError)
        ->and($result->error)->toContain('5MB');
});

test('strips <script> content so a price selector cannot accidentally match JS source', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html>
        <head><title>Decoy</title></head>
        <body>
            <script>const FAKE = '€999,99 in JS source';</script>
            <div class="real-price">€10,00</div>
        </body>
        </html>
        HTML;

    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.real-price',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('10.00')
        ->and($result->rawPrice)->not->toContain('999');
});

test('strips <style> content but preserves JSON-LD scripts for the price fallback', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html>
        <head>
            <style>.price::before { content: '€999,99'; }</style>
            <script type="application/ld+json">{"@type":"Product","offers":{"price":"42.00","priceCurrency":"EUR"}}</script>
        </head>
        <body><div id="root"></div></body>
        </html>
        HTML;

    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/a' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = ScraperFixtures::makeScraper()->scrape(new ScrapeRequest(
        url: 'https://shop.example.com/a',
        priceSelector: '.does-not-exist',
    ));

    expect($result->status)->toBe(ScrapeStatus::Ok)
        ->and($result->price)->toBe('42.00')
        ->and($result->currency)->toBe('EUR');
});
