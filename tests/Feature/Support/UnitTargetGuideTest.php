<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
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

it('reads the chart low from the history kept in the unit the alert compares in', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = guideShop($product, 'ah.nl', '21.99', '800.00', 'piece');

    foreach ([['23.99', 20, 10], ['21.99', 10, null]] as [$price, $startedDaysAgo, $endedDaysAgo]) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'best_value_shop_id' => $shop->id,
            'cheapest_price' => $price,
            'pack_quantity' => '800.00',
            'pack_unit' => 'piece',
            'started_at' => now()->subDays($startedDaysAgo),
            'ended_at' => $endedDaysAgo === null ? null : now()->subDays($endedDaysAgo),
        ]);
    }

    // Matched on the unit code: the chart's label reads "per piece" in English
    // and "per stuk" in Dutch, and neither is a key.
    expect(new UnitTargetGuide($product->refresh())->history())->toBe(['lowest' => 0.0275]);
});

it('ignores a history kept in another unit', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = guideShop($product, 'ah.nl', '1.69', '200.00', 'g');

    foreach ([20, 10] as $daysAgo) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'cheapest_price' => '2.00',
            'pack_quantity' => '8.00',
            'pack_unit' => 'piece',
            'started_at' => now()->subDays($daysAgo),
            'ended_at' => $daysAgo === 20 ? now()->subDays(10) : null,
        ]);
    }

    expect(new UnitTargetGuide($product->refresh())->history())->toBeNull();
});
