<?php declare(strict_types=1);

use App\Support\VerdictReading;

test('sizeKey gives the same key for every spelling of a pack size', function (): void {
    expect(VerdictReading::sizeKey(500.0, 'g'))
        ->toBe('500.00 g')
        ->toBe(VerdictReading::sizeKey('500', 'g'));
});

test('sizeKey treats a quantity that is not finite as no quantity', function (float $quantity): void {
    expect(VerdictReading::sizeKey($quantity, 'g'))->toBe(VerdictReading::sizeKey(null, 'g'));
})->with([
    'INF' => [INF],
    '-INF' => [-INF],
    'NAN' => [NAN],
]);
