<?php declare(strict_types=1);

use App\Support\PackLine;
use App\Support\PackSize;

it('states the pack a unit price comes to', function (float $quantity, string $unit, string $unitPrice, string $expected, string $currency = 'EUR'): void {
    $size = PackSize::of($quantity, $unit) ?? throw new LogicException('Not a pack size.');

    if (! is_numeric($unitPrice)) {
        throw new LogicException('Not a unit price.');
    }

    expect(PackLine::forUnitPrice($unitPrice, $currency, $size))->toBe($expected);
})->with([
    'pieces' => [5.0, 'piece', '2.5400', '€12.70 for 5 pieces'],
    'grams' => [400.0, 'g', '10.0000', '€4.00 for 400 g'],
    'fractional grams' => [2.25, 'g', '200.0000', '€0.45 for 2.25 g'],
    'millilitres' => [330.0, 'ml', '3.0000', '€0.99 for 330 ml'],
    // Rounded up, €1.00 for 30 pieces would sit above a 0.0333 target and never alert.
    'rounds down to stay at or under the unit price' => [30.0, 'piece', '0.0333', '€0.99 for 30 pieces'],
    'rounds down to whole yen' => [5.0, 'piece', '2.5400', '¥12 for 5 pieces', 'JPY'],
]);
