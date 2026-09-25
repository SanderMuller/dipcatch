<?php declare(strict_types=1);

use App\Charts\ChartScale;

it('starts the axis on a round step with room below the lowest price', function (): void {
    // €1.75 to €1.98: the margin takes the floor to €1.7155, and a 0.1 step
    // rounds it down to €1.70, five cents under the lowest price.
    expect(ChartScale::ticks([1.98, 1.85, 1.75]))->toBe([1.7, 1.8, 1.9, 2.0])
        // Tablets at a few cents each keep their decimals.
        ->and(ChartScale::ticks([0.0325, 0.0275], decimals: 4))->toBe([0.026, 0.028, 0.03, 0.032, 0.034]);
});

it('steps in whole cents a shop would print', function (): void {
    // A 2.5 step here would have printed €0.98, €1.00, €1.03.
    expect(ChartScale::ticks([1.0, 1.08]))->toBe([0.95, 1.0, 1.05, 1.1]);
});

it('never steps finer than the labels print, so no two read alike', function (): void {
    // A 0.005 step here would print €1.00 twice.
    expect(ChartScale::ticks([1.0, 1.01]))->toBe([0.99, 1.0, 1.01])
        ->and(ChartScale::ticks([0.0325, 0.0324], decimals: 4))->toBe([0.0323, 0.0324, 0.0325]);
});

it('leaves room below a price that never moved', function (): void {
    expect(ChartScale::ticks([20.0, 20.0])[0])->toBeLessThan(20.0)->toBeGreaterThan(15.0);
});

it('never starts below zero, and has no ticks without prices', function (): void {
    expect(ChartScale::ticks([0.01, 0.5])[0])->toBe(0.0)
        ->and(ChartScale::ticks([]))->toBe([]);
});
