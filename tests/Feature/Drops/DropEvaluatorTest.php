<?php declare(strict_types=1);

use App\Models\Product;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\ReferenceValue;

function ref(string $value, string $kind = ReferenceValue::KIND_MEDIAN_30D, int $sampleSize = 30): ReferenceValue
{
    return new ReferenceValue(value: $value, kind: $kind, sampleSize: $sampleSize);
}

test('absolute trigger fires even when percent threshold not met', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '50.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '90.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeTrue()
        ->and((float) $outcome->dropAbsolute)->toBe(10.0)
        ->and((float) $outcome->dropPercent)->toBe(10.0)
        ->and((float) $outcome->thresholdAbs)->toBe(5.0)
        ->and((float) $outcome->thresholdPct)->toBe(50.0);
});

test('percent trigger fires even when absolute threshold not met', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '500.00',
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '90.00', ref('100.00'));

    expect($outcome->belowThreshold)->toBeTrue()
        ->and((float) $outcome->dropPercent)->toBe(10.0)
        ->and((float) $outcome->thresholdPct)->toBe(5.0);
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

test('product override wins over tier default for pct only', function (): void {
    $product = Product::factory()->create([
        'drop_threshold_pct' => '20.00',
        'drop_threshold_abs' => null,
    ]);

    $outcome = new DropEvaluator()->evaluate($product, '85.00', ref('50.00'));

    // Reference 50 → tier 25-100 → abs default 7.
    expect((float) $outcome->thresholdPct)->toBe(20.0)
        ->and((float) $outcome->thresholdAbs)->toBe(7.0);
});
