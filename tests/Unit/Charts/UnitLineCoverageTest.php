<?php declare(strict_types=1);

use App\Charts\UnitLineCoverage;

it('measures how much of the charted time the per-unit line covers', function (): void {
    expect(UnitLineCoverage::of([
        ['date' => '2026-09-01T00:00:00Z', 'price' => 2.0, 'unit' => null],
        ['date' => '2026-09-05T00:00:00Z', 'price' => 1.9, 'unit' => null],
    ]))->toBe(0.0)
        ->and(UnitLineCoverage::of([
            ['date' => '2026-09-01T00:00:00Z', 'price' => 2.0, 'unit' => 8.0],
            ['date' => '2026-09-05T00:00:00Z', 'price' => 1.9, 'unit' => 7.6],
        ]))->toBe(1.0)
        // A size that appeared on day four of five: the last quarter.
        ->and(UnitLineCoverage::of([
            ['date' => '2026-09-01T00:00:00Z', 'price' => 2.0, 'unit' => null],
            ['date' => '2026-09-04T00:00:00Z', 'price' => 1.9, 'unit' => 7.6],
            ['date' => '2026-09-05T00:00:00Z', 'price' => 1.9, 'unit' => 7.6],
        ]))->toBe(0.25);
});

it('leaves a gap in the middle of the line uncovered', function (): void {
    // A line on the first day and the last, and nothing for the three between.
    expect(UnitLineCoverage::of([
        ['date' => '2026-09-01T00:00:00Z', 'price' => 2.0, 'unit' => 8.0],
        ['date' => '2026-09-02T00:00:00Z', 'price' => 1.9, 'unit' => null],
        ['date' => '2026-09-05T00:00:00Z', 'price' => 1.9, 'unit' => 7.6],
    ]))->toBe(0.25);
});

it('treats a single moment and nothing charted as not covered', function (): void {
    expect(UnitLineCoverage::of([['date' => '2026-09-01T00:00:00Z', 'price' => 2.0, 'unit' => 8.0]]))->toBe(0.0)
        ->and(UnitLineCoverage::of([]))->toBe(0.0);
});
