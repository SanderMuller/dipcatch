<?php declare(strict_types=1);

use App\Console\Commands\RecheckActiveShopsCommand;
use App\Console\Commands\RetryReferenceShopsCommand;
use App\Enums\ProbeFailure;
use App\Enums\ShopKind;
use App\Jobs\CheckShopPrice;
use App\Livewire\Products\ProductShow;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\AlsoWorthChecking;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function comparisonKlarnaUrl(): string
{
    return 'https://www.klarna.com/nl/shopping/pl/cl456/3202266158/Huisdieren/Hill-s-10kg/';
}

beforeEach(function (): void {
    Cache::flush();
});

function runComparisonCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));
}

/**
 * A product whose cheapest shop is Klarna at 76.05, beside a real shop at 81.99.
 *
 * @param  array<string, mixed>  $productState
 * @return array{0: Product, 1: Shop, 2: Shop}
 */
function productWithKlarnaCheapest(array $productState = []): array
{
    $product = Product::factory()->state($productState)->create(['currency' => 'EUR']);
    $klarna = Shop::factory()->for($product)->create(['url' => comparisonKlarnaUrl(), 'current_price' => '76.05']);
    $zooplus = Shop::factory()->for($product)->create(['url' => 'https://www.zooplus.nl/shop/hills/939943', 'current_price' => '81.99']);
    PriceCheck::factory()->for($klarna)->create(['price' => '76.05']);
    $product->recomputeCheapestShop();

    return [$product->refresh(), $klarna, $zooplus];
}

test('a scheduled check of a Klarna shop makes it a link without reading the page', function (): void {
    Http::fake();
    [$product, $klarna, $zooplus] = productWithKlarnaCheapest();
    expect($product->cheapest_shop_id)->toBe($klarna->id);

    runComparisonCheck($klarna);

    Http::assertNothingSent();
    $klarna->refresh();
    expect($klarna->kind)->toBe(ShopKind::Reference)
        ->and($klarna->unreadable_reason)->toBe(ProbeFailure::NotAShop->value)
        ->and($klarna->current_price)->toBeNull()
        ->and($klarna->priceChecks()->count())->toBe(1)
        ->and($product->refresh()->cheapest_shop_id)->toBe($zooplus->id)
        ->and($product->cheapest_price)->toBe('81.99')
        ->and($product->best_value_shop_id)->not->toBe($klarna->id);
});

test('a check queued before its shop became a link reads nothing', function (): void {
    Http::fake();
    [, $klarna] = productWithKlarnaCheapest();
    $klarna->keepAsComparisonLink();

    runComparisonCheck($klarna);

    Http::assertNothingSent();
    expect($klarna->refresh()->priceChecks()->count())->toBe(1);
});

test('the recheck command makes Klarna shops links on paused products too', function (): void {
    Queue::fake();
    [$product, $klarna] = productWithKlarnaCheapest(['active' => false]);
    $other = Shop::factory()->for(Product::factory()->create())->create(['url' => 'https://www.expert.nl/p/1']);

    $this->artisan(RecheckActiveShopsCommand::class)->assertSuccessful();

    expect($klarna->refresh()->isComparisonLink())->toBeTrue()
        ->and($other->refresh()->kind)->toBe(ShopKind::Tracked);
});

test('a comparison link is never retried and never offered to check by hand', function (): void {
    Http::fake();
    [$product, $klarna] = productWithKlarnaCheapest(['active' => true]);
    $klarna->keepAsComparisonLink();
    $blocked = Shop::factory()->for($product)->create(['url' => 'https://www.bol.com/p/1', 'kind' => ShopKind::Reference, 'unreadable_reason' => 'blocked', 'current_price' => null]);

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    expect(AlsoWorthChecking::of($product))->toBe([['host' => 'bol.com', 'url' => $blocked->url]])
        ->and($klarna->refresh()->retried_at)->toBeNull();
});

test('a comparison link says what it is, not that the page cannot be read', function (): void {
    [$product, $klarna] = productWithKlarnaCheapest();
    $klarna->keepAsComparisonLink();
    $this->actingAs($product->user()->sole());

    expect($klarna->refresh()->linkNote())->toBe('Comparison site — DipCatch tracks the shops it lists, not this page.');
    Livewire::test(ProductShow::class, ['product' => $product])
        ->assertSee('Comparison site — DipCatch tracks the shops it lists, not this page.')
        ->assertDontSee('DipCatch cannot read this shop');
});

test('a comparison link pointed at a shop becomes a tracked shop again', function (): void {
    [, $klarna] = productWithKlarnaCheapest();
    $klarna->keepAsComparisonLink();

    $klarna->updateUrl(UrlNormalizer::normalize('https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck?sku=MP28084'));

    expect($klarna->refresh()->kind)->toBe(ShopKind::Tracked)
        ->and($klarna->unreadable_reason)->toBeNull();
});

test('a comparison link pointed at another comparison page stays a link', function (): void {
    [, $klarna] = productWithKlarnaCheapest();
    $klarna->keepAsComparisonLink();

    $klarna->updateUrl(UrlNormalizer::normalize('https://www.klarna.com/nl/shopping/pl/cl456/1/Huisdieren/other/'));

    expect($klarna->refresh()->isComparisonLink())->toBeTrue();
});

test('a check that read the page before the row was repointed writes nothing', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1', 'current_price' => '50.00']);
    Http::fake(function () use ($shop) {
        // The user repoints the row to Klarna while the page is on its way.
        Shop::query()->find($shop->id)?->updateUrl(UrlNormalizer::normalize(comparisonKlarnaUrl()));

        return Http::response(withJsonLd(json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Demo', 'offers' => ['@type' => 'Offer', 'price' => '12.00', 'priceCurrency' => 'EUR']], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']);
    });

    runComparisonCheck($shop);

    expect($shop->refresh()->current_price)->toBeNull()
        ->and($shop->priceChecks()->count())->toBe(0);
});

test('a conversion re-checks the host under the lock', function (): void {
    [, $klarna] = productWithKlarnaCheapest();
    $stale = Shop::query()->find($klarna->id);
    $klarna->updateUrl(UrlNormalizer::normalize('https://www.medpets.nl/p/1'));

    expect($stale?->keepAsComparisonLink())->toBeFalse()
        ->and($klarna->refresh()->kind)->toBe(ShopKind::Tracked);
});

/** A shop page with one JSON-LD offer at `$price` euro. */
function comparisonShopPage(string $price): string
{
    return withJsonLd(json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Demo', 'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR']], JSON_THROW_ON_ERROR));
}

test('a check that read the page before the row was repointed to another shop writes nothing', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1', 'current_price' => '50.00']);
    Http::fake(function () use ($shop) {
        // The user repoints the row to another shop while the page is on its way.
        Shop::query()->find($shop->id)?->updateUrl(UrlNormalizer::normalize('https://other-shop.example/p/2'));

        return Http::response(comparisonShopPage('12.00'), 200, ['Content-Type' => 'text/html']);
    });

    runComparisonCheck($shop);

    // The repoint cleared the price; the old page's reading must not fill it.
    $shop->refresh();
    expect($shop->host)->toBe('other-shop.example')
        ->and($shop->kind)->toBe(ShopKind::Tracked)
        ->and($shop->current_price)->toBeNull()
        ->and($shop->last_checked_at)->toBeNull()
        ->and($shop->priceChecks()->count())->toBe(0);
});

test('a product whose only priced shop is Klarna has no cheapest or best-value shop after the check', function (): void {
    Http::fake();
    $product = Product::factory()->create(['currency' => 'EUR']);
    $klarna = Shop::factory()->for($product)->create(['url' => comparisonKlarnaUrl(), 'current_price' => '76.05', 'pack_quantity' => '10000.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://www.zooplus.nl/shop/hills/939943', 'current_price' => null]);
    $product->recomputeCheapestShop();
    expect($product->refresh()->cheapest_shop_id)->toBe($klarna->id)
        ->and($product->best_value_shop_id)->toBe($klarna->id);

    runComparisonCheck($klarna);

    $product->refresh();
    expect($product->cheapest_shop_id)->toBeNull()
        ->and($product->cheapest_price)->toBeNull()
        ->and($product->best_value_shop_id)->toBeNull();
});

test('a comparison link pointed at a shop competes after its check', function (): void {
    [$product, $klarna, $zooplus] = productWithKlarnaCheapest();
    $klarna->keepAsComparisonLink();
    expect($product->refresh()->cheapest_shop_id)->toBe($zooplus->id);
    $klarna->refresh()->updateUrl(UrlNormalizer::normalize('https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck'));
    Http::fake([
        'https://www.medpets.nl/robots.txt' => Http::response('', 404),
        'https://www.medpets.nl/*' => Http::response(comparisonShopPage('70.00'), 200, ['Content-Type' => 'text/html']),
    ]);

    runComparisonCheck($klarna);

    expect($klarna->refresh()->current_price)->toBe('70.00')
        ->and($product->refresh()->cheapest_shop_id)->toBe($klarna->id)
        ->and($product->cheapest_price)->toBe('70.00');
});
