<?php declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScraperFixtures;

beforeEach(function (): void {
    config()->set('scraper.user_agent', 'TestBot/1.0');
    config()->set('scraper.timeout', 5);
    Cache::flush();
});

test('detects Schema.org microdata price selector + OG title + OG image', function (): void {
    Http::fake([
        'https://shop.example.com/keyboard' => Http::response(ScraperFixtures::load('shopify_microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (ScraperFixtures::makeAutoDetect())('https://shop.example.com/keyboard');

    expect($result->selectors)->toContain('[itemprop="price"]')
        ->and($result->selectors[0])->toBe('[itemprop="price"]')
        ->and($result->title)->toBe('Mech Keyboard')
        ->and($result->imageUrl)->toBe('https://cdn.example.com/mech-kb.png')
        ->and($result->error)->toBeNull();
});

test('detects OG product:price:amount selector', function (): void {
    Http::fake([
        'https://shop.example.com/coffee' => Http::response(ScraperFixtures::load('og_product.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (ScraperFixtures::makeAutoDetect())('https://shop.example.com/coffee');

    expect($result->selectors)->toContain('meta[property="product:price:amount"]')
        ->and($result->title)->toBe('Coffee Beans 1kg (OG)')
        ->and($result->imageUrl)->toBe('https://shop.example.com/img/beans.jpg');
});

test('detects common class selectors when present', function (): void {
    Http::fake([
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (ScraperFixtures::makeAutoDetect())('https://shop.example.com/wireless-headphones');

    expect($result->selectors)->toContain('.product-price')
        ->and($result->title)->toBe('Acme Wireless Headphones');
});

test('JSON-LD only page yields no DOM selector but still returns title', function (): void {
    Http::fake([
        'https://shop.example.com/camera' => Http::response(ScraperFixtures::load('json_ld.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (ScraperFixtures::makeAutoDetect())('https://shop.example.com/camera');

    expect($result->selectors)->toBe([])
        ->and($result->title)->toBe('Vintage Camera');
});

test('returns failure on a non-2xx response', function (): void {
    Http::fake([
        'https://shop.example.com/missing' => Http::response('Not found', 404),
    ]);

    $result = (ScraperFixtures::makeAutoDetect())('https://shop.example.com/missing');

    expect($result->selectors)->toBe([])
        ->and($result->error)->toContain('404');
});

test('caches the fetched HTML for 60s — second call does not re-fetch', function (): void {
    Http::fake([
        'https://shop.example.com/keyboard' => Http::response(ScraperFixtures::load('shopify_microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $detect = ScraperFixtures::makeAutoDetect();
    $detect('https://shop.example.com/keyboard');
    $detect('https://shop.example.com/keyboard');

    Http::assertSentCount(1);
});

test('does not cache failed fetches', function (): void {
    Http::fake([
        'https://shop.example.com/missing' => Http::sequence()
            ->push('Not found', 404)
            ->push(ScraperFixtures::load('shopify_microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $detect = ScraperFixtures::makeAutoDetect();

    expect(($detect)('https://shop.example.com/missing')->error)->toContain('404');
    // Second call should re-fetch and succeed.
    expect(($detect)('https://shop.example.com/missing')->error)->toBeNull();
});
