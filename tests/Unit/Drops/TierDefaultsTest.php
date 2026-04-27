<?php declare(strict_types=1);

use App\Services\Drops\TierDefaults;

test('returns under-25 tier for prices below 25', function (): void {
    expect(TierDefaults::for('0'))->toBe(['pct' => 15.0, 'abs' => 3.0])
        ->and(TierDefaults::for('24.99'))->toBe(['pct' => 15.0, 'abs' => 3.0]);
});

test('returns 25-100 tier at and above 25, below 100', function (): void {
    expect(TierDefaults::for('25'))->toBe(['pct' => 10.0, 'abs' => 7.0])
        ->and(TierDefaults::for('99.99'))->toBe(['pct' => 10.0, 'abs' => 7.0]);
});

test('returns 100-500 tier at and above 100, below 500', function (): void {
    expect(TierDefaults::for('100'))->toBe(['pct' => 8.0, 'abs' => 25.0])
        ->and(TierDefaults::for('499.99'))->toBe(['pct' => 8.0, 'abs' => 25.0]);
});

test('returns 500-2000 tier at and above 500, below 2000', function (): void {
    expect(TierDefaults::for('500'))->toBe(['pct' => 5.0, 'abs' => 50.0])
        ->and(TierDefaults::for('1999.99'))->toBe(['pct' => 5.0, 'abs' => 50.0]);
});

test('returns top tier at and above 2000', function (): void {
    expect(TierDefaults::for('2000'))->toBe(['pct' => 3.0, 'abs' => 100.0])
        ->and(TierDefaults::for('99999'))->toBe(['pct' => 3.0, 'abs' => 100.0]);
});

test('accepts numeric input forms', function (): void {
    expect(TierDefaults::for(50))->toBe(['pct' => 10.0, 'abs' => 7.0])
        ->and(TierDefaults::for(50.0))->toBe(['pct' => 10.0, 'abs' => 7.0])
        ->and(TierDefaults::for('50.00'))->toBe(['pct' => 10.0, 'abs' => 7.0]);
});
