<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonResult;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\DmApi\DmApiSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;

function dmPage(): string
{
    return 'https://www.dm.de/p/d/1625966/balea-shampoo-reparierend-beauty-essentials';
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * The product API's answer as dm.de's own page receives it, trimmed to the
 * fields that matter (2026-10-06).
 */
function fakeDmProductApi(int $status = 200): void
{
    Http::fake([
        'products.dm.de/product/products/detail/DE/dan/1625966' => $status === 200
            ? Http::response([
                'dan' => 1625966,
                'gtin' => 4070765053340,
                'price' => ['price' => ['current' => ['value' => "1,45\u{a0}€"]]],
                'seoInformation' => ['structuredData' => [
                    'brand' => 'Balea',
                    'gtin' => '4070765053340',
                    'image' => 'https://products.dm-static.com/images/balea-shampoo',
                    'name' => 'Shampoo reparierend beauty essentials, 400 ml',
                    'price' => 1.45,
                    'priceCurrency' => 'EUR',
                    'sku' => '1625966',
                ]],
            ])
            : Http::response(['title' => 'Entschuldigung...'], $status),
    ]);
}

it('reads a dm page through the product API: price, title with brand, photo and barcode', function (): void {
    fakeDmProductApi();

    $snapshot = new DmApiSource()->resolve(dmPage())->snapshot;

    expect($snapshot?->title)->toBe('Balea Shampoo reparierend beauty essentials, 400 ml')
        ->and($snapshot?->price)->toBe('1.45')
        ->and($snapshot?->currency)->toBe('EUR')
        ->and($snapshot?->gtin)->toBe('4070765053340')
        ->and($snapshot?->inStock)->toBeNull()
        ->and($snapshot?->imageUrl)->toBe('https://products.dm-static.com/images/balea-shampoo');
});

it('reports a miss, never a price, when dm does not answer', function (int $status, string $reason): void {
    fakeDmProductApi($status);

    $result = new DmApiSource()->resolve(dmPage());

    expect($result->snapshot)->toBeNull()
        ->and($result->missReason)->toBe($reason);
})->with([
    'no such product' => [404, CheckjebonResult::REASON_NOT_IN_DATASET],
    'dm is down' => [503, CheckjebonResult::REASON_API_ERROR],
]);

it('reads only dm product pages, and asks the Austrian shop for dm.at', function (): void {
    Http::fake(['products.dm.de/product/products/detail/AT/dan/1703586' => Http::response(['seoInformation' => ['structuredData' => [
        'name' => 'Balea Shampoo Intensiv Pflege, 300 ml', 'brand' => 'Balea', 'price' => 0.85, 'priceCurrency' => 'EUR',
    ]]])]);

    expect(new DmApiSource()->supports('www.dm.at'))->toBeTrue()
        ->and(new DmApiSource()->supports('rossmann.de'))->toBeFalse()
        ->and(new DmApiSource()->resolve('https://www.dm.at/p/d/1703586/balea-shampoo')->snapshot?->title)->toBe('Balea Shampoo Intensiv Pflege, 300 ml')
        ->and(new DmApiSource()->resolve('https://www.dm.de/search?query=shampoo')->missReason)->toBe(CheckjebonResult::REASON_UNRECOGNIZED_URL);
});

it('adds a dm shop through the API, since the page itself carries no price', function (): void {
    fakeDmProductApi();
    $product = Product::factory()->create(['currency' => 'EUR']);

    $outcome = app(ProbeShopUrl::class)($product, dmPage(), $product->user()->sole());

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('dm-api')
        ->and($outcome->snapshot?->price)->toBe('1.45');
});

it('checks a tracked dm shop through the API', function (): void {
    fakeDmProductApi();
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create(['url' => dmPage(), 'current_price' => '1.95']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->adapter_key)->toBe('dm-api')
        ->and((string) $shop->current_price)->toBe('1.45');
});
