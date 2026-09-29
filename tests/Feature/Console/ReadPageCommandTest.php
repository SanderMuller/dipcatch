<?php declare(strict_types=1);

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::put('dipcatch:robots:bol.com', [], 3600);
});

test('prints what the adapters read from a page, and writes nothing', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/read-me/' => Http::response(withJsonLd(json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Read me',
        'offers' => ['@type' => 'Offer', 'price' => '12.50', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR)))]);

    $this->artisan('dipcatch:read-page', ['url' => 'https://www.bol.com/nl/nl/p/read-me/'])
        ->expectsOutputToContain('12.50 EUR')
        ->expectsOutputToContain('Read me')
        ->assertSuccessful();

    expect(Shop::query()->count())->toBe(0);
});

test('fails and names the reason when no price can be read', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/empty/' => Http::response('<html><body><h1>Nothing here</h1></body></html>')]);

    $this->artisan('dipcatch:read-page', ['url' => 'https://www.bol.com/nl/nl/p/empty/'])
        ->expectsOutputToContain('bol_extraction_failed')
        ->assertFailed();
});

test('fails with the fetch error when the shop does not answer', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/down/' => Http::response('', 403)]);

    $this->artisan('dipcatch:read-page', ['url' => 'https://www.bol.com/nl/nl/p/down/'])
        ->assertFailed();
});
