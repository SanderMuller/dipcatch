<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestShops;
use App\Jobs\LookUpBolOffers;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Services\BolApi\BolCatalogClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
    Http::preventStrayRequests();
});

/** A bol.com Catalog API answer for one product with its best offer. */
function bolProductJson(string $ean, string $title, string $id, float $price): array
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));

    return [
        'ean' => $ean,
        'bolProductId' => $id,
        'title' => $title,
        'url' => "https://www.bol.com/nl/nl/p/{$slug}/{$id}/",
        'offer' => ['price' => $price, 'deliveryDescription' => 'Morgen in huis'],
    ];
}

function fakeBolApi(array $routes): void
{
    Http::fake(['login.bol.com/*' => Http::response(['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 299])] + $routes);
}

function runBolLookup(Product $product): LookUpBolOffers
{
    $job = new LookUpBolOffers($product->id)->withFakeQueueInteractions();
    $job->handle(app(BolCatalogClient::class), app(SuggestShops::class));

    return $job;
}

it('finds bol.com by barcode, stores it as a suggestion, and logs in once', function (): void {
    $product = Product::factory()->create(['title' => 'Sensodyne Rapid relief dagelijkse tandpasta', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://drogist.test/p/1', 'gtin' => '5054563110503', 'pack_quantity' => '75.00', 'pack_unit' => 'ml']);
    seedChains();
    fakeBolApi(['api.bol.com/marketing/catalog/v1/products/5054563110503*' => Http::response(bolProductJson('5054563110503', 'Sensodyne Tandpasta Rapid Relief 75ml', '9200000077118993', 8.81))]);

    runBolLookup($product);
    runBolLookup($product);

    $row = CheckjebonPrice::query()->where('supermarket', 'bol')->sole();
    expect($row->only(['external_id', 'price', 'link', 'ean']))->toBe([
        'external_id' => '9200000077118993',
        'price' => '8.81',
        'link' => 'sensodyne-tandpasta-rapid-relief-75ml/9200000077118993/',
        'ean' => '5054563110503',
    ]);

    // One login and two lookups: bol rate-limits its login far below the API.
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/products/5054563110503')
        && $request->hasHeader('Accept-Language', 'nl')
        && str_contains($request->url(), 'country-code=NL'));

    expect(collect(app(SuggestShops::class)($product->refresh(), verify: false))->pluck('chain')->all())->toContain('bol');
});

it('searches by name when no shop reports a barcode, and keeps only what would be suggested', function (): void {
    $product = Product::factory()->create(['title' => 'Remia Fritessaus classic', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://saus.test/p/1', 'pack_quantity' => '500.00', 'pack_unit' => 'ml']);
    fakeBolApi(['api.bol.com/marketing/catalog/v1/products/search*' => Http::response(['page' => 1, 'resultsPerPage' => 10, 'totalPages' => 1, 'totalResults' => 2, 'results' => [
        bolProductJson('8710448620013', 'Remia Fritessaus Classic 500 ml', '9300000001', 1.99),
        bolProductJson('8712100325328', 'Calvé Pindasaus 650 ml', '9300000002', 3.49),
    ]])]);

    runBolLookup($product);

    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->pluck('name')->all())->toBe(['Remia Fritessaus Classic 500 ml']);
});

it('waits and tries again when bol rate-limits, instead of failing', function (): void {
    $product = Product::factory()->create(['title' => 'Remia Fritessaus classic', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://saus.test/p/1']);
    fakeBolApi(['api.bol.com/*' => Http::response(['title' => 'Too Many Requests'], 429)]);

    runBolLookup($product)->assertReleased(10);

    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->count())->toBe(0);
});

it('is queued for a product only with API credentials', function (): void {
    Queue::fake();
    $product = Product::factory()->create(['currency' => 'EUR']);

    LookUpBolOffers::dispatchFor($product);
    Queue::assertPushed(LookUpBolOffers::class, fn (LookUpBolOffers $job): bool => $job->productId === $product->id);

    config()->set('services.bol.api.client_id', '');
    Queue::fake();
    LookUpBolOffers::dispatchFor($product);
    Queue::assertNothingPushed();
});
