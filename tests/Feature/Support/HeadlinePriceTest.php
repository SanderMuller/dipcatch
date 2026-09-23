<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Support\HeadlinePrice;
use App\Support\PackLine;

/**
 * @param  array<string, mixed>  $columns
 */
function headlineShop(Product $product, string $host, string $price, ?string $quantity, ?string $unit, array $columns = []): Shop
{
    $shop = Shop::factory()->for($product)->create(['url' => "https://{$host}/p/1"]);
    $shop->forceFill(['current_price' => $price, 'pack_quantity' => $quantity, 'pack_unit' => $unit, ...$columns])->save();

    return $shop;
}

function headlineOf(Product $product): HeadlinePrice
{
    $product->refresh()->recomputeCheapestShop();

    return HeadlinePrice::of($product->refresh()->load('shops'));
}

it('leads with the best value per unit and notes the lowest pack price', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'ah.nl', '12.99', '400.00', 'piece');
    headlineShop($product, 'kruidvat.nl', '21.99', '800.00', 'piece');

    $headline = headlineOf($product);

    expect($headline->isPerUnit())->toBeTrue()
        ->and($headline->shop?->host)->toBe('kruidvat.nl')
        ->and($headline->unitPrice())->toBe('0.0275')
        ->and($headline->text())->toBe('€0.0275 /piece')
        ->and($headline->packPrice())->toBe('21.99')
        ->and($headline->packLine()?->text())->toBe('€21.99 for 800 pieces')
        ->and($headline->lowestShop?->host)->toBe('ah.nl')
        // 0.0325 against 0.0275 a piece.
        ->and($headline->lowestCostsMorePercent())->toBe(18);
});

it('has no lowest-price note when one shop holds both answers', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'lidl.nl', '1.99', '370.00', 'g');
    headlineShop($product, 'ah.nl', '2.49', '370.00', 'g');

    $headline = headlineOf($product);

    expect($headline->shop?->host)->toBe('lidl.nl')
        ->and($headline->lowestShop)->toBeNull()
        ->and($headline->lowestCostsMorePercent())->toBeNull();
});

it('leads with the pack price when no shop states a pack size', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'bol.com', '11.95', null, null);
    headlineShop($product, 'ah.nl', '12.49', null, null);

    $headline = headlineOf($product);

    expect($headline->isPerUnit())->toBeFalse()
        ->and($headline->shop?->host)->toBe('bol.com')
        ->and($headline->unitPrice())->toBeNull()
        ->and($headline->text())->toBe('€11.95')
        ->and($headline->lowestShop)->toBeNull();
});

it('leads with the pack price when no sized shop can be bought from', function (): void {
    // The sized shop is sold out, so nothing can win per unit — the rule the
    // alert basis follows too.
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'ah.nl', '1.69', '200.00', 'g', ['current_in_stock' => false]);
    headlineShop($product, 'jumbo.com', '1.89', null, null);

    $headline = headlineOf($product);

    expect($headline->isPerUnit())->toBeFalse()
        ->and($headline->unit)->toBeNull()
        ->and($headline->shop?->host)->toBe('jumbo.com')
        ->and($product->refresh()->dropComparisonUnit())->toBeNull();
});

it('states no percentage when the lowest-price shop is outside the comparison', function (): void {
    // A box of twelve on a product compared per kilo: excluded, so it has no
    // unit price to set against the best value. Its own size still describes it.
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'ah.nl', '4.99', '660.00', 'g');
    headlineShop($product, 'jumbo.com', '5.49', '660.00', 'g');
    headlineShop($product, 'fitnesscandy.nl', '3.00', '12.00', 'piece');

    $headline = headlineOf($product);

    expect($headline->shop?->host)->toBe('ah.nl')
        ->and($headline->lowestShop?->host)->toBe('fitnesscandy.nl')
        ->and($headline->lowestCostsMorePercent())->toBeNull()
        ->and($headline->packLine($headline->lowestShop)?->text())->toBe('€3.00 for 12 pieces');
});

it('marks an estimated size and carries the deal terms in the pack line', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    headlineShop($product, 'ah.nl', '1.69', '200.00', 'g');
    $silent = headlineShop($product, 'jumbo.com', '1.79', null, null);
    $deal = headlineShop($product, 'dirk.nl', '3.00', '200.00', 'g', [
        'single_item_price' => '3.50',
        'bundle_quantity' => 2,
        'bundle_total_price' => '6.00',
    ]);

    $headline = headlineOf($product);

    expect(PackLine::of($silent->refresh(), $headline->packs)->text())->toBe('€1.79 for 200 g (estimated)')
        ->and(PackLine::of($deal->refresh(), $headline->packs)->text())->toStartWith('€3.00 for 200 g · 2 for €6.00');
});

it('keeps the last recorded price for a product with nothing to buy now', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR', 'cheapest_price' => '4.79']);

    $headline = HeadlinePrice::of($product->load('shops'));

    expect($headline->shop)->toBeNull()
        ->and($headline->text())->toBe('€4.79');
});
