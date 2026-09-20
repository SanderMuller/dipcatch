<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Drops\ReferenceValue;

/**
 * `Product::activeDropPercent()` drives every live drop badge. It mixes an
 * event's reference with the product's current price, so both sides have to be
 * the same kind of money — the event says which in `comparison_unit`.
 */
function badgeProduct(string $unit = 'g'): Product
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1']);
    $shop->forceFill([
        'currency' => 'EUR',
        'current_price' => '8.00',
        'current_in_stock' => true,
        'pack_quantity' => '1000.00',
        'pack_unit' => $unit,
    ])->save();

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('reads a pack-basis event against the pack price', function (): void {
    $product = badgeProduct();
    $product->forceFill(['last_notified_price' => '8.00', 'last_notified_unit' => null])->save();

    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $product->user_id,
        'currency' => 'EUR',
        'reference_price' => '10.00',
        'comparison_unit' => null,
        'drop_pct' => '20.0',
        'fired_at' => now(),
    ]);

    // 8.00 a pack against a 10.00 pack reference.
    expect($product->refresh()->activeDropPercent())->toBe(20);
});

it('reads a unit-basis event against the unit price', function (): void {
    $product = badgeProduct();
    $product->forceFill(['last_notified_price' => '8.00', 'last_notified_unit' => 'g'])->save();

    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $product->user_id,
        'currency' => 'EUR',
        'reference_price' => '10.00',
        'reference_unit_price' => '10.00',
        'comparison_unit' => 'g',
        'drop_pct' => '20.0',
        'fired_at' => now(),
    ]);

    // 8.00 for a kilo against 10.00 a kilo. Reading the pack price here would
    // answer the same by luck at this pack size, so the next case is the one
    // that proves the basis is chosen rather than guessed.
    expect($product->refresh()->activeDropPercent())->toBe(20);
});

it('falls back to the recorded percentage when the event carries no pack reference', function (): void {
    // A cross-size drop has no pack reference at all. The badge cannot recompute
    // from null, so it reports what the alert reported.
    $product = badgeProduct();
    $product->forceFill(['last_notified_price' => '8.00', 'last_notified_unit' => 'g'])->save();

    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $product->user_id,
        'currency' => 'EUR',
        'reference_price' => null,
        'reference_unit_price' => null,
        'comparison_unit' => 'g',
        'drop_pct' => '17.0',
        'fired_at' => now(),
    ]);

    expect($product->refresh()->activeDropPercent())->toBe(17);
});

it('clears a latch left in the other basis even when the price has not recovered', function (): void {
    // The upward branch. A latch holding pack money says nothing about a unit
    // comparison, so it is cleared rather than measured against.
    $product = badgeProduct();
    $product->forceFill([
        'last_notified_price' => '4.00',
        'last_notified_unit' => null,
        'last_notified_at' => now()->subDay(),
    ])->save();

    $reference = new ReferenceValue(value: '20.00', kind: ReferenceValue::KIND_MEDIAN_30D, sampleSize: 9, unit: 'g');

    app(DetectDrop::class)->clearLatchIfRecovered($product, '8.00', $reference);

    $product->refresh();

    expect($product->last_notified_price)->toBeNull()
        ->and($product->last_notified_unit)->toBeNull();
});
