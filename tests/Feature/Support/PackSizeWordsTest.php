<?php declare(strict_types=1);

use App\Support\PackSize;
use App\Support\UnitWord;

test('names the piece unit in the reader\'s language', function (): void {
    $pieces = PackSize::parse('4 rollen');

    expect($pieces?->label())->toBe('/piece')
        ->and(PackSize::of(500.0, 'g')?->label())->toBe('/kg')
        ->and(PackSize::of(500.0, 'ml')?->label())->toBe('/l');

    app()->setLocale('nl');

    expect($pieces?->label())->toBe('/stuk');
});

test('describes a pack the way a shopper names it', function (float $quantity, string $unit, string $expected): void {
    $size = PackSize::of($quantity, $unit);
    assert($size instanceof PackSize);

    expect(UnitWord::pack($size))->toBe($expected);
})->with([
    [500.0, 'g', '500 g'],
    [1500.0, 'g', '1.5 kg'],
    [330.0, 'ml', '330 ml'],
    [1500.0, 'ml', '1.5 L'],
    [1.0, 'piece', '1 piece'],
    [800.0, 'piece', '800 pieces'],
]);
