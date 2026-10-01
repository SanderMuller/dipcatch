<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\BolApi\BolApiSource;
use App\Services\Checkjebon\CheckjebonResult;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;

function bolPage(): string
{
    return 'https://www.bol.com/nl/nl/p/sensodyne-tandpasta-rapid-relief-75-ml/9200000077118993/';
}

beforeEach(function (): void {
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
    Http::preventStrayRequests();
});

function fakeBolProductApi(int $productStatus = 200): void
{
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        'api.bol.com/marketing/catalog/v1/products/9200000077118993/to-ean*' => Http::response(['ean' => '5054563110503']),
        'api.bol.com/marketing/catalog/v1/products/5054563110503*' => $productStatus === 200
            ? Http::response([
                'ean' => '5054563110503',
                'title' => 'Sensodyne Tandpasta Rapid Relief 75ml',
                'url' => bolPage(),
                'image' => ['url' => 'https://media.s-bol.com/x/250x200.jpg', 'width' => 250, 'height' => 200, 'mimeType' => 'image/jpeg'],
                'offer' => ['price' => 8.81, 'strikethroughPrice' => 9.99],
            ])
            : Http::response(['title' => 'Error'], $productStatus),
    ]);
}

it('reads a bol.com page through the Catalog API: price, was-price, photo and barcode', function (): void {
    fakeBolProductApi();

    $snapshot = app(BolApiSource::class)->resolve(bolPage())->snapshot;

    expect($snapshot?->title)->toBe('Sensodyne Tandpasta Rapid Relief 75ml')
        ->and($snapshot?->price)->toBe('8.81')
        ->and($snapshot?->claimedRegularPrice)->toBe('9.99')
        ->and($snapshot?->gtin)->toBe('5054563110503')
        ->and($snapshot?->imageUrl)->toBe('https://media.s-bol.com/x/250x200.jpg');
});

it('reports a miss, never a price, when bol does not answer', function (int $status, string $reason): void {
    fakeBolProductApi($status);

    $result = app(BolApiSource::class)->resolve(bolPage());

    expect($result->snapshot)->toBeNull()
        ->and($result->missReason)->toBe($reason);
})->with([
    'no such product' => [404, CheckjebonResult::REASON_NOT_IN_DATASET],
    'bol is down' => [503, CheckjebonResult::REASON_API_ERROR],
]);

it('is used only for bol.com, and only with API credentials', function (): void {
    expect(app(BolApiSource::class)->supports('bol.com'))->toBeTrue()
        ->and(app(BolApiSource::class)->supports('www.bol.com'))->toBeTrue()
        ->and(app(BolApiSource::class)->supports('jumbo.com'))->toBeFalse();

    config()->set('services.bol.api.client_id', '');

    expect(app(BolApiSource::class)->supports('bol.com'))->toBeFalse();
});

it('adds a bol.com shop through the API without opening the page, which bol may block', function (): void {
    fakeBolProductApi();
    $product = Product::factory()->create(['currency' => 'EUR']);

    $outcome = app(ProbeShopUrl::class)($product, bolPage(), $product->user()->sole());

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('bol-api')
        ->and($outcome->snapshot?->price)->toBe('8.81');
});

it('checks a tracked bol.com shop through the API', function (): void {
    fakeBolProductApi();
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create(['url' => bolPage(), 'current_price' => '9.50']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->adapter_key)->toBe('bol-api')
        ->and((string) $shop->current_price)->toBe('8.81');
});
