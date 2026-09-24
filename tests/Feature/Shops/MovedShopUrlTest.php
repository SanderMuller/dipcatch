<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\MovedShopUrl;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

function checkShopAt(string $url, ?Product $product = null): Shop
{
    $shop = Shop::factory()->for($product ?? Product::factory()->create(['currency' => 'EUR']))->create(['url' => $url, 'currency' => 'EUR']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    return $shop->refresh();
}

/**
 * @param  array<string, mixed>  $routes
 */
function fakeShop(array $routes): void
{
    Http::fake(['https://shop.test/robots.txt' => Http::response('', 404), ...$routes]);
}

it('stores the address a page moved to for good, so the next check asks once', function (): void {
    fakeShop([
        'https://shop.test/p/beans' => Http::response('', 301, ['Location' => 'https://shop.test/p/beans/']),
        'https://shop.test/p/beans/' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    $shop = checkShopAt('https://shop.test/p/beans');

    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->url)->toBe('https://shop.test/p/beans/')
        ->and($shop->url_hash)->toBe(UrlNormalizer::hash('https://shop.test/p/beans/'));
});

it('follows a permanent move to a new path on the same shop', function (): void {
    fakeShop([
        'https://shop.test/old' => Http::response('', 308, ['Location' => 'https://shop.test/new']),
        'https://shop.test/new' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(checkShopAt('https://shop.test/old')->url)->toBe('https://shop.test/new');
});

it('keeps the stored address after a temporary redirect', function (int $status): void {
    fakeShop([
        'https://shop.test/p/beans' => Http::response('', $status, ['Location' => 'https://shop.test/sale/beans']),
        'https://shop.test/sale/beans' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(checkShopAt('https://shop.test/p/beans')->url)->toBe('https://shop.test/p/beans');
})->with([302, 303, 307]);

it('keeps the stored address when a permanent hop is followed by a temporary one', function (): void {
    fakeShop([
        'https://shop.test/a' => Http::response('', 301, ['Location' => 'https://shop.test/b']),
        'https://shop.test/b' => Http::response('', 302, ['Location' => 'https://shop.test/c']),
        'https://shop.test/c' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(checkShopAt('https://shop.test/a')->url)->toBe('https://shop.test/a');
});

it('never follows a move to another shop', function (): void {
    fakeShop([
        'https://shop.test/p/beans' => Http::response('', 301, ['Location' => 'https://other.test/p/beans']),
        'https://other.test/robots.txt' => Http::response('', 404),
        'https://other.test/p/beans' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(checkShopAt('https://shop.test/p/beans')->url)->toBe('https://shop.test/p/beans');
});

it('keeps the stored address when the move drops a parameter it named', function (): void {
    // Without ?variant= the page shows its default, not the tracked variant.
    fakeShop([
        'https://shop.test/p/whey?variant=2' => Http::response('', 301, ['Location' => 'https://shop.test/products/whey']),
        'https://shop.test/products/whey' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(checkShopAt('https://shop.test/p/whey?variant=2')->url)->toBe('https://shop.test/p/whey?variant=2');
});

it('keeps the stored address when another shop of the product already tracks the new one', function (): void {
    fakeShop([
        'https://shop.test/old' => Http::response('', 301, ['Location' => 'https://shop.test/new']),
        'https://shop.test/new' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
    ]);
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://shop.test/new', 'currency' => 'EUR']);

    $shop = checkShopAt('https://shop.test/old', $product);

    expect($shop->url)->toBe('https://shop.test/old')
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok);
});

it('keeps the stored address when the page it moved to could not be read', function (): void {
    fakeShop([
        'https://shop.test/old' => Http::response('', 301, ['Location' => 'https://shop.test/new']),
        'https://shop.test/new' => Http::response('<html><body>no price here</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $shop = checkShopAt('https://shop.test/old');

    expect($shop->last_status)->not->toBe(ScrapeStatus::Ok)
        ->and($shop->url)->toBe('https://shop.test/old');
});

it('never stores a move over a URL someone changed while the page was fetched', function (): void {
    $shop = Shop::factory()->create(['url' => 'https://shop.test/repointed', 'currency' => 'EUR']);

    expect(MovedShopUrl::updates($shop, 'https://shop.test/old', 'https://shop.test/new'))->toBe([]);
});

it('keeps the stored address when the move drops one of two repeated parameters', function (): void {
    $shop = Shop::factory()->create(['url' => 'https://shop.test/p?variant=1&variant=2', 'currency' => 'EUR']);

    expect(MovedShopUrl::updates($shop, $shop->url, 'https://shop.test/q?variant=2'))->toBe([]);
});
