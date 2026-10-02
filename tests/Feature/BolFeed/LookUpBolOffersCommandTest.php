<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestShops;
use App\Jobs\LookUpBolOffers;
use App\Models\Product;
use App\Models\Shop;
use App\Services\BolApi\BolCatalogClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
    Cache::flush();
});

function bolLookupProduct(string $currency = 'EUR', bool $active = true): Product
{
    $product = Product::factory()->create(['currency' => $currency, 'active' => $active]);
    Shop::factory()->for($product)->create();

    return $product;
}

it('asks bol.com about the products it was not asked about lately, a few a second', function (): void {
    Queue::fake();
    $stale = bolLookupProduct();
    $another = bolLookupProduct();
    $fresh = bolLookupProduct();
    Cache::put("bol-offers:looked-up:{$fresh->id}", true, now()->addDay());
    bolLookupProduct(active: false);
    bolLookupProduct(currency: 'USD');
    Product::factory()->create(['currency' => 'EUR', 'active' => true]);
    $third = bolLookupProduct();

    $this->artisan('dipcatch:look-up-bol-offers')->assertSuccessful()->expectsOutputToContain('Queued 3 bol.com lookups.');

    Queue::assertPushed(LookUpBolOffers::class, 3);
    Queue::assertNotPushed(LookUpBolOffers::class, fn (LookUpBolOffers $job): bool => $job->productId === (string) $fresh->id);
    // Two a second: the third waits a second.
    expect(Queue::pushed(LookUpBolOffers::class)->map(fn (LookUpBolOffers $job): mixed => $job->delay)->sort()->values()->all())->toBe([0, 0, 1])
        ->and(Queue::pushed(LookUpBolOffers::class)->map(fn (LookUpBolOffers $job): string => $job->productId)->sort()->values()->all())
        ->toBe(collect([$stale->id, $another->id, $third->id])->map(strval(...))->sort()->values()->all());
});

it('remembers a lookup, so the next run leaves the product alone', function (): void {
    Http::preventStrayRequests();
    $product = bolLookupProduct();
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        'api.bol.com/*' => Http::response(['results' => []]),
    ]);

    new LookUpBolOffers((string) $product->id)->handle(app(BolCatalogClient::class), app(SuggestShops::class));

    expect(LookUpBolOffers::lookedUpRecently((string) $product->id))->toBeTrue();
});

it('does nothing without API credentials', function (): void {
    config()->set('services.bol.api.client_id', '');
    Queue::fake();
    bolLookupProduct();

    $this->artisan('dipcatch:look-up-bol-offers')->assertSuccessful();

    Queue::assertNothingPushed();
});
