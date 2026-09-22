<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Models\Product;
use App\Models\Shop;
use App\Support\UnitTargetGuide;

/**
 * @param  array<string, mixed>  $columns
 */
function guideShop(Product $product, string $host, string $price, string $quantity, string $unit, array $columns = []): Shop
{
    $shop = Shop::factory()->for($product)->create(['url' => "https://{$host}/p/1", 'currency' => 'EUR']);
    $shop->forceFill(['current_price' => $price, 'pack_quantity' => $quantity, 'pack_unit' => $unit, 'current_in_stock' => true, ...$columns])->save();

    return $shop;
}

it('lists the packs the alert compares, cheapest per unit first', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    guideShop($product, 'ah.nl', '1.69', '200.00', 'g');
    guideShop($product, 'lidl.nl', '1.99', '370.00', 'g');
    guideShop($product, 'jumbo.com', '2.49', '1500.00', 'g');
    $product->refresh()->recomputeCheapestShop();

    $packs = new UnitTargetGuide($product->refresh())->packs();

    expect(array_column($packs, 'host'))->toBe(['jumbo.com', 'lidl.nl', 'ah.nl'])
        ->and(array_column($packs, 'pack'))->toBe(['1.5 kg', '370 g', '200 g'])
        // How many kilos one pack holds: the pack price divided by it is the kilo price.
        ->and(array_column($packs, 'perPack'))->toBe([1.5, 0.37, 0.2])
        ->and($packs[0]['bestValue'])->toBeTrue();
});

it('leaves out a shop the alert cannot use', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    guideShop($product, 'ah.nl', '1.69', '200.00', 'g');
    guideShop($product, 'soldout.nl', '0.99', '200.00', 'g', ['current_in_stock' => false]);
    guideShop($product, 'dead.nl', '0.89', '200.00', 'g', ['health' => ShopHealth::Dead]);

    $packs = new UnitTargetGuide($product->refresh())->packs();

    expect(array_column($packs, 'host'))->toBe(['ah.nl']);
});

it('counts pieces and litres as the unit a pack holds', function (): void {
    $pieces = Product::factory()->create(['currency' => 'EUR']);
    guideShop($pieces, 'ah.nl', '2.40', '8.00', 'piece');
    $litres = Product::factory()->create(['currency' => 'EUR']);
    guideShop($litres, 'ah.nl', '1.50', '1500.00', 'ml');

    expect(new UnitTargetGuide($pieces->refresh())->packs()[0])->toMatchArray(['pack' => '8 pieces', 'perPack' => 8.0, 'unitPrice' => 0.3])
        ->and(new UnitTargetGuide($litres->refresh())->packs()[0])->toMatchArray(['pack' => '1.5 L', 'perPack' => 1.5, 'unitPrice' => 1.0]);
});

it('has no chart low without history', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    guideShop($product, 'ah.nl', '1.69', '200.00', 'g');

    expect(new UnitTargetGuide($product->refresh())->history())->toBeNull();
});
