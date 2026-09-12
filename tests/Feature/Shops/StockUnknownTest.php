<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Services\ShopFetcher\ShopFetcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stock has three answers. A page that does not say must not be recorded as
 * available: that is what reported a sold-out product as buyable.
 */
beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

/**
 * @return array<string, PromiseInterface>
 */
function pageWithoutStock(string $price = '19.95'): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Sanimed Skin Sensitive Kat',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

test('a check that could not read stock stores unknown, not available', function (): void {
    Http::fake(pageWithoutStock());

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_in_stock' => true,
        'adapter_key' => 'jsonld',
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect($shop->fresh()?->current_in_stock)->toBeNull()
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->value('in_stock'))->toBeNull();
});

test('a shop of unknown stock can still be the cheapest', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['current_price' => '9.00', 'currency' => 'EUR', 'current_in_stock' => true]);
    $unknown = Shop::factory()->for($product)->create(['current_price' => '4.00', 'currency' => 'EUR', 'current_in_stock' => null]);

    $product->recomputeCheapestShop();

    expect($product->fresh()?->cheapest_shop_id)->toBe($unknown->id);
});

test('a shop the page says is sold out is not the cheapest', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $available = Shop::factory()->for($product)->create(['current_price' => '9.00', 'currency' => 'EUR', 'current_in_stock' => true]);
    Shop::factory()->for($product)->create(['current_price' => '4.00', 'currency' => 'EUR', 'current_in_stock' => false]);

    $product->recomputeCheapestShop();

    expect($product->fresh()?->cheapest_shop_id)->toBe($available->id);
});

test('a recheck reads the page words too, not only the probe', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Sanimed Skin Sensitive Kat',
        'offers' => ['@type' => 'Offer', 'price' => '19.95', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response(
            withJsonLd($json) . '<p>Tijdelijk niet leverbaar</p>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_in_stock' => true,
        'adapter_key' => 'jsonld',
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect($shop->fresh()?->current_in_stock)->toBeFalse();
});
