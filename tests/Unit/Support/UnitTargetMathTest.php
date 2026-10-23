<?php declare(strict_types=1);

use App\Support\PackSize;
use App\Support\UnitTargetMath;

it('gives the target a price for a pack comes to, cut to four decimals', function (string $price, PackSize $size, ?string $expected): void {
    expect(UnitTargetMath::fromPackPrice($price, UnitTargetMath::perPack($size)))->toBe($expected);
})->with([
    // €22.80 for 660 g is €34.545454… a kilo: cut, so the target is never above the owner's price.
    '660 g' => ['22.80', PackSize::of(660, 'g'), '34.5454'],
    '1.5 l' => ['3.00', PackSize::of(1500, 'ml'), '2.0000'],
    'a box of 3' => ['1.00', PackSize::of(3, 'piece'), '0.3333'],
]);

it('takes a percentage off a pack price before working out the price per unit', function (string $percent, ?string $expected): void {
    expect(UnitTargetMath::percentUnderPack('1.00', $percent, '12'))->toBe($expected);
})->with([
    // 25% off €1.00 for 12 is €0.0625 each, where off €0.0833 each it cuts to €0.0624.
    'a quarter' => ['25', '0.0625'],
    'nothing' => ['0', null],
    'everything' => ['100', null],
]);

it('takes an amount off a pack price, per unit', function (string $amount, ?string $expected): void {
    $size = PackSize::of(660, 'g') ?? throw new RuntimeException('660 g is a size');

    expect(UnitTargetMath::amountUnder('21.00', $amount, UnitTargetMath::perPack($size)))->toBe($expected);
})->with([
    'two euro' => ['2.00', '28.7878'],
    'the whole price' => ['21.00', null],
    'more than the price' => ['25.00', null],
    'nothing' => ['0', null],
]);

it('refuses a figure the column cannot hold', function (): void {
    expect(UnitTargetMath::fromPackPrice('0.00001', '1'))->toBeNull()
        ->and(UnitTargetMath::fromPackPrice('2.99', '0'))->toBeNull();
});

it('reads a number written with an exponent', function (): void {
    expect(UnitTargetMath::percentUnderPack('21.00', '1e1', '0.66'))->toBe('28.6363')
        ->and(UnitTargetMath::amountUnder('21.00', '2e0', '0.66'))->toBe('28.7878');
});
