<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\CheckjebonPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;

function runCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );
}

function checkjebonShop(string $url = 'https://ah.nl/producten/product/wi257/roomkaas'): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    return Shop::factory()->for($product)->create([
        'url' => $url,
        'adapter_key' => 'checkjebon',
        'current_price' => '9.99',
    ]);
}

test('recheck of a dataset host falls back to the table when the AH API is down', function (): void {
    Http::fake(ahApiDownFakes());
    Http::preventStrayRequests();

    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => '1.15',
        'size' => '125 g',
        'refreshed_at' => now(),
    ]);

    $shop = checkjebonShop();
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('1.15')
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('checkjebon')
        ->and($shop->consecutive_failures)->toBe(0);

    $check = PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->first();
    expect((string) $check->price)->toBe('1.15')
        ->and($check->status)->toBe(ScrapeStatus::Ok);
});

test('recheck of an AH shop uses the mobile API bonus price', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69'));
    Http::preventStrayRequests();

    $shop = checkjebonShop('https://ah.nl/producten/product/wi526381/lay-s-naturel');
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('1.69')
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('ah-api')
        ->and($shop->consecutive_failures)->toBe(0);
});

test('dataset miss records EmptyMatch and ticks the failure counter', function (): void {
    Http::fake(ahApiDownFakes());
    // Table non-empty for AH, but not this product.
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi1',
        'name' => 'Other',
        'price' => '2.00',
        'size' => null,
        'refreshed_at' => now(),
    ]);

    $shop = checkjebonShop('https://ah.nl/producten/product/wi257/roomkaas');
    runCheck($shop);

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::EmptyMatch)
        ->and($shop->consecutive_failures)->toBe(1)
        ->and($shop->last_error)->toBe('checkjebon:not_in_dataset');

    $check = PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->first();
    expect($check->status)->toBe(ScrapeStatus::EmptyMatch);
});

test('re-listed product recovers the shop on the next check', function (): void {
    Http::fake(ahApiDownFakes());
    $shop = checkjebonShop();
    runCheck($shop); // miss: table empty
    expect($shop->refresh()->consecutive_failures)->toBe(1);

    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => '1.25',
        'size' => null,
        'refreshed_at' => now(),
    ]);

    runCheck($shop);
    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $shop->current_price)->toBe('1.25');
});
