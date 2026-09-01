<?php declare(strict_types=1);

use App\Models\Shop;
use App\Support\UrlNormalizer;

test('unitPrice renders per kg, per liter and per piece', function (string $quantity, string $unit, string $price, string $expected, string $label): void {
    $shop = Shop::factory()->create([
        'current_price' => $price,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
    ]);

    expect($shop->unitPrice())->toBe($expected)
        ->and($shop->unitPriceLabel())->toBe($label);
})->with([
    ['200.00', 'g', '1.69', '8.45', '/kg'],
    ['750.00', 'ml', '2.25', '3.00', '/l'],
    ['4.00', 'piece', '1.80', '0.45', '/stuk'],
]);

test('unitPrice is null without a price or without a complete pack size', function (?string $price, ?string $quantity, ?string $unit): void {
    $shop = Shop::factory()->create([
        'current_price' => $price,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
    ]);

    expect($shop->unitPrice())->toBeNull()
        ->and($shop->unitPriceLabel())->toBeNull();
})->with([
    [null, '200.00', 'g'],
    ['1.69', null, 'g'],
    ['1.69', '200.00', null],
]);

test('updateUrl clears the pack columns so a stale size never prices a new product', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://shop.example.com/p/1',
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);

    expect($shop->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/2')))->toBeTrue();

    $shop->refresh();
    expect($shop->pack_quantity)->toBeNull()
        ->and($shop->pack_unit)->toBeNull();
});
