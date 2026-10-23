<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\ReferenceValue;

function ref(string $value, string $kind = ReferenceValue::KIND_MEDIAN_30D, int $sampleSize = 30): ReferenceValue
{
    return new ReferenceValue(value: $value, kind: $kind, sampleSize: $sampleSize);
}

test('absolute trigger fires even when percent threshold not met', function (): void {
    $product = Product::factory()->create();

    // Reference 1500 → tier 500-2000: 5% or €50. €60 off is only 4%.
    $outcome = new DropEvaluator()->evaluate($product, '1440.00', ref('1500.00'));

    expect($outcome->belowThreshold)->toBeTrue()
        ->and((float) $outcome->dropAbsolute)->toBe(60.0)
        ->and((float) $outcome->dropPercent)->toBe(4.0)
        ->and((float) $outcome->thresholdAbs)->toBe(50.0)
        ->and((float) $outcome->thresholdPct)->toBe(5.0);
});

test('percent trigger fires even when absolute threshold not met', function (): void {
    $product = Product::factory()->create();

    // Reference 100 → tier 100-500: 8% or €25. 10% off is only €10.
    $outcome = new DropEvaluator()->evaluate($product, '90.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeTrue()
        ->and((float) $outcome->dropAbsolute)->toBe(10.0)
        ->and((float) $outcome->dropPercent)->toBe(10.0)
        ->and((float) $outcome->thresholdPct)->toBe(8.0)
        ->and((float) $outcome->thresholdAbs)->toBe(25.0);
});

test('both triggers met still yields belowThreshold true', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '50.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeTrue();
});

test('neither trigger met yields belowThreshold false', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '50.00',
        'drop_threshold_abs' => '50.00',
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '95.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeFalse()
        ->and((float) $outcome->dropAbsolute)->toBe(5.0)
        ->and((float) $outcome->dropPercent)->toBe(5.0);
});

test('price increase produces negative drop and stays below threshold', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '110.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeFalse()
        ->and((float) $outcome->dropAbsolute)->toBe(-10.0)
        ->and((float) $outcome->dropPercent)->toBe(-10.0);
});

test('falls back to tier defaults when product overrides are null', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => null,
        'drop_threshold_abs' => null,
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '90.00', ref('100.00'));

    // 100.00 sits in the 100-500 tier per spec? No: bands say "< 100" → 25-100 tier. 100 lands in 100-500.
    expect((float) $outcome->thresholdPct)->toBe(8.0)
        ->and((float) $outcome->thresholdAbs)->toBe(25.0);
});

test('a drop threshold stored on the product is ignored: the tier defaults decide', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '20.00',
        'drop_threshold_abs' => '100.00',
    ]);

    // Reference 50 → tier 25-100: 10% or €7. €5 off is 10%: under the stored
    // 20% and €100, so only the default 10% can fire it.
    $outcome = new DropEvaluator()->evaluate($product, '45.00', ref('50.00'));

    expect($outcome->belowThreshold)->toBeTrue()
        ->and((float) $outcome->thresholdPct)->toBe(10.0)
        ->and((float) $outcome->thresholdAbs)->toBe(7.0);
});

test('a product with a pack target alerts on no default drop', function (): void {
    $product = Product::factory()->withPackTarget('50.00', ['current_price' => '60.00'])->create();

    // 40% and €40 off: far past any default.
    $outcome = new DropEvaluator()->evaluate($product->refresh(), '60.00', ref('100.00'));

    expect($product->hasActiveTarget())->toBeTrue()
        ->and($outcome->belowThreshold)->toBeFalse()
        ->and($outcome->thresholdPct)->toBeNull()
        ->and($outcome->thresholdAbs)->toBeNull();
});

test('a product with a pack target does not alert on a drop threshold stored on it either', function (): void {
    $product = Product::factory()
        ->withPackTarget('50.00', ['current_price' => '85.00'])
        ->create(['drop_threshold_pct' => '10.00', 'drop_threshold_abs' => '5.00']);

    $outcome = new DropEvaluator()->evaluate($product->refresh(), '85.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeFalse()
        ->and($outcome->thresholdPct)->toBeNull()
        ->and($outcome->thresholdAbs)->toBeNull();
});

test('a unit-price target on a free account switches the default drops off too', function (): void {
    $product = Product::factory()->create([
        'unit_price_target' => '5.00',
        'drop_threshold_pct' => null,
        'drop_threshold_abs' => null,
    ]);
    Shop::factory()->for($product)->create(['current_price' => '6.00', 'pack_quantity' => 500, 'pack_unit' => 'g']);

    $outcome = new DropEvaluator()->evaluate($product, '60.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeFalse();
});

test('a unit-price target a Pro account is alerted on switches the default drops off', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'unit_price_target' => '5.00',
        'drop_threshold_pct' => null,
        'drop_threshold_abs' => null,
    ]);
    Shop::factory()->for($product)->create(['current_price' => '6.00', 'pack_quantity' => 500, 'pack_unit' => 'g']);

    $outcome = new DropEvaluator()->evaluate($product, '60.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeFalse();
});

test('a unit-price target keeps the default drops while the sized shop is out of stock', function (): void {
    // The comparison can fall back to pack prices; the target then has no
    // unit price to check and cannot fire.
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'unit_price_target' => '5.00',
        'drop_threshold_pct' => null,
        'drop_threshold_abs' => null,
    ]);
    Shop::factory()->for($product)->create(['current_price' => '6.00', 'pack_quantity' => 500, 'pack_unit' => 'g', 'current_in_stock' => false]);
    Shop::factory()->for($product)->create(['current_price' => '7.00', 'pack_quantity' => null, 'pack_unit' => null, 'current_in_stock' => true]);
    $product->recomputeCheapestShop();

    $outcome = new DropEvaluator()->evaluate($product->refresh(), '60.00', ref('100.00'));

    expect($product->bestValueShop()?->unitPrice())->toBeNull()
        ->and($outcome->belowThreshold)->toBeTrue();
});

test('a unit-price target on a product that compares no unit keeps the default drops', function (): void {
    // The target cannot fire without a unit to measure, so switching the
    // defaults off would leave the product with no working alert.
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'unit_price_target' => '5.00',
        'drop_threshold_pct' => null,
        'drop_threshold_abs' => null,
    ]);
    Shop::factory()->for($product)->create(['current_price' => '6.00', 'pack_quantity' => null, 'pack_unit' => null]);

    $outcome = new DropEvaluator()->evaluate($product, '60.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeTrue();
});
