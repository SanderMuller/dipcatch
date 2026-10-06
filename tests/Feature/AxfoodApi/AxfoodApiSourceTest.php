<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Models\Product;
use App\Services\AxfoodApi\AxfoodApiSource;
use App\Services\Checkjebon\CheckjebonResult;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * The product endpoint's answer as willys.se's own page receives it,
 * trimmed to the fields that matter (2026-10-06).
 *
 * @param  list<array<mixed>>  $promotions
 * @return array<string, mixed>
 */
function axfoodProduct(array $promotions = [], bool $outOfStock = false): array
{
    return [
        'code' => '101544610_ST',
        'name' => 'Originalet Mellanrost Bryggkaffe',
        'manufacturer' => 'Löfbergs',
        'displayVolume' => '450g',
        'priceValue' => 59.9,
        'outOfStock' => $outOfStock,
        'ean' => '7310050001395',
        'image' => ['url' => 'https://assets.axfood.se/image/upload/f_auto,t_500/07310050001395'],
        'potentialPromotions' => $promotions,
    ];
}

/**
 * @return array<string, mixed>
 */
function axfoodPromotion(string $campaignType, ?int $qualifyingCount = 1): array
{
    return [
        'campaignType' => $campaignType,
        'qualifyingCount' => $qualifyingCount,
        'price' => ['currencyIso' => 'SEK', 'value' => 52.9, 'priceType' => 'BUY'],
        'productCodes' => ['101544610_ST'],
    ];
}

it('reads a Willys page through the product endpoint: price, brand, size, barcode', function (): void {
    Http::fake(['www.willys.se/axfood/rest/p/101544610_ST' => Http::response(axfoodProduct())]);

    $snapshot = new AxfoodApiSource()->resolve('https://www.willys.se/produkt/Originalet-Mellanrost-Bryggkaffe-101544610_ST')->snapshot;

    expect($snapshot?->title)->toBe('Löfbergs Originalet Mellanrost Bryggkaffe')
        ->and($snapshot?->price)->toBe('59.9')
        ->and($snapshot?->currency)->toBe('SEK')
        ->and($snapshot?->packSize)->toBe('450g')
        ->and($snapshot?->gtin)->toBe('7310050001395')
        ->and($snapshot?->inStock)->toBeTrue();
});

it('takes a campaign price anyone gets, never a member or multi-buy price', function (array $promotion, string $price): void {
    Http::fake(['www.willys.se/axfood/rest/p/101544610_ST' => Http::response(axfoodProduct([$promotion]))]);

    $snapshot = new AxfoodApiSource()->resolve('https://www.willys.se/produkt/Originalet-Mellanrost-Bryggkaffe-101544610_ST')->snapshot;

    expect($snapshot?->price)->toBe($price);
})->with([
    'for everyone' => [axfoodPromotion('GENERAL'), '52.9'],
    'Willys Plus members only' => [axfoodPromotion('LOYALTY'), '59.9'],
    'a multi-buy' => [axfoodPromotion('GENERAL', qualifyingCount: 2), '59.9'],
]);

it('leaves stock unknown when the endpoint does not state it', function (): void {
    $product = axfoodProduct();
    unset($product['outOfStock']);
    Http::fake(['www.willys.se/axfood/rest/p/101544610_ST' => Http::response($product)]);

    expect(new AxfoodApiSource()->resolve('https://www.willys.se/produkt/Originalet-Mellanrost-Bryggkaffe-101544610_ST')->snapshot?->inStock)->toBeNull();
});

it('reads Hemköp too, and refuses a product priced per kilo', function (): void {
    Http::fake(['www.hemkop.se/axfood/rest/p/101544610_ST' => Http::response(axfoodProduct(outOfStock: true))]);

    expect(new AxfoodApiSource()->resolve('https://www.hemkop.se/produkt/Originalet-Mellanrost-Bryggkaffe-101544610_ST')->snapshot?->inStock)->toBeFalse()
        ->and(new AxfoodApiSource()->resolve('https://www.willys.se/produkt/Lagrad-Ost-101197478_KG')->missReason)->toBe(CheckjebonResult::REASON_UNRECOGNIZED_URL);
});

it('adds a Willys shop through the endpoint, since the page itself carries no price', function (): void {
    Http::fake(['www.willys.se/axfood/rest/p/101544610_ST' => Http::response(axfoodProduct())]);
    $product = Product::factory()->create(['currency' => 'SEK']);

    $outcome = app(ProbeShopUrl::class)($product, 'https://www.willys.se/produkt/Originalet-Mellanrost-Bryggkaffe-101544610_ST', $product->user()->sole());

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('axfood-api');
});
