<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;

/**
 * A product's comparison unit defines an epoch. Numbers from either side of a
 * change share no scale, so a time-weighted median across the boundary averages
 * things nobody sold. This is the median path, which the fallback-only cases in
 * UnitBasisDropsTest never reach.
 */
function epochShop(Product $product, string $host, string $quantity, string $unit): Shop
{
    $shop = Shop::factory()->for($product)->create(['url' => "https://{$host}/p/1"]);

    $shop->forceFill([
        'currency' => 'EUR',
        'current_price' => '10.00',
        'current_in_stock' => true,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
    ])->save();

    return $shop;
}

function epochSegment(Product $product, Shop $shop, string $price, string $quantity, string $unit, int $fromHours, ?int $toHours): void
{
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => $price,
        'best_value_shop_id' => $shop->id,
        'best_value_price' => $price,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
        'started_at' => now()->subHours($fromHours),
        'ended_at' => $toHours === null ? null : now()->subHours($toHours),
    ]);
}

function epochChecks(Shop $shop, int $count): void
{
    foreach (range(1, $count) as $i) {
        PriceCheck::factory()->for($shop)->create([
            'status' => ScrapeStatus::Ok,
            'checked_at' => now()->subHours($i),
        ]);
    }
}

it('takes the median inside one comparison unit and ignores the epoch before it', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = epochShop($product, 'ah.nl', '500.00', 'g');

    // Three segments measured in millilitres, then four in grams. A median over
    // all seven would mix euros-per-litre into a euros-per-kilo figure.
    epochSegment($product, $shop, '5.00', '500.00', 'ml', 200, 180);
    epochSegment($product, $shop, '6.00', '500.00', 'ml', 180, 160);
    epochSegment($product, $shop, '7.00', '500.00', 'ml', 160, 140);
    epochSegment($product, $shop, '9.00', '500.00', 'g', 140, 120);
    epochSegment($product, $shop, '10.00', '500.00', 'g', 120, 100);
    epochSegment($product, $shop, '11.00', '500.00', 'g', 100, 80);
    epochSegment($product, $shop, '10.00', '500.00', 'g', 80, null);

    epochChecks($shop, 12);

    $reference = app(Reference::class)->compute($product);

    // Every gram segment is 500 g, so the unit prices are 18.00, 20.00, 22.00
    // and 20.00 per kilo. Nothing from the millilitre epoch is in range.
    expect($reference)->toBeInstanceOf(ReferenceValue::class)
        ->and($reference->unit)->toBe('g')
        ->and($reference->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and((float) $reference->value)->toBeGreaterThanOrEqual(18.0)
        ->and((float) $reference->value)->toBeLessThanOrEqual(22.0);
});

it('starts a new epoch when the same shop corrects its pack size', function (): void {
    // Foodello reported 55 g for a box of twelve at an unchanged 12.00. The
    // corrected segments are the only ones that describe what is sold.
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = epochShop($product, 'foodello.nl', '660.00', 'g');

    epochSegment($product, $shop, '12.00', '55.00', 'g', 200, 180);
    epochSegment($product, $shop, '12.00', '55.00', 'g', 180, 160);
    epochSegment($product, $shop, '12.00', '660.00', 'g', 160, 140);
    epochSegment($product, $shop, '12.00', '660.00', 'g', 140, null);

    epochChecks($shop, 12);

    $reference = app(Reference::class)->compute($product);

    // 12.00 for 660 g is 18.18/kg. The wrong 55 g segments read 218.18/kg, and
    // one of those in the median would make the next ordinary price move look
    // like a collapse.
    expect((string) $reference?->value)->toBe('18.18')
        ->and($reference?->packQuantity)->toBe(660.0);
});

it('has no pack reference when the epoch spans two pack sizes', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $small = epochShop($product, 'ah.nl', '500.00', 'g');
    $large = epochShop($product, 'jumbo.com', '1000.00', 'g');

    epochSegment($product, $small, '5.00', '500.00', 'g', 200, 150);
    epochSegment($product, $large, '9.00', '1000.00', 'g', 150, 100);
    epochSegment($product, $small, '5.00', '500.00', 'g', 100, null);

    epochChecks($small, 12);

    $reference = app(Reference::class)->compute($product);

    // No shared size, so there is no money figure to compare against and the
    // absolute threshold sits the round out rather than reporting a saving
    // nobody made.
    expect($reference?->unit)->toBe('g')
        ->and($reference?->packValue)->toBeNull()
        ->and($reference?->packBasis())->toBeNull();
});

it('does not graduate a median on checks taken before the product could be compared', function (): void {
    // Every product that existed before unit comparison is in this shape: a
    // long sizeless segment, dozens of checks, and one new sized segment.
    // Counting the whole window would graduate a median standing on that one.
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = epochShop($product, 'ah.nl', '500.00', 'g');

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '10.00',
        'started_at' => now()->subHours(300),
        'ended_at' => now()->subHours(2),
    ]);
    epochSegment($product, $shop, '9.00', '500.00', 'g', 2, null);

    epochChecks($shop, 12);

    $reference = app(Reference::class)->compute($product);

    expect($reference?->kind)->toBe(ReferenceValue::KIND_INITIAL)
        ->and($reference?->unit)->toBe('g');
});
