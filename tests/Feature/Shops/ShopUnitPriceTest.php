<?php declare(strict_types=1);

use App\Models\Product;
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

test('updateUrl clears everything the previous product taught this offer', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '10.00',
        'current_in_stock' => true,
        'conditional_price' => '8.00',
        'conditional_label' => 'With the shop card',
        'conditional_starts_at' => now()->subDay(),
        'conditional_ends_at' => now()->addDay(),
        'promotion_starts_at' => now()->subDay(),
        'promotion_ends_at' => now()->addDay(),
        'promotion_label' => 'Two for one',
        'last_success_at' => now()->subHour(),
        'last_checked_at' => now()->subHour(),
    ]);

    expect($shop->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/2')))->toBeTrue();

    $shop->refresh();
    expect($shop->current_price)->toBeNull()
        ->and($shop->current_in_stock)->toBeNull()
        ->and($shop->conditional_price)->toBeNull()
        ->and($shop->conditional_label)->toBeNull()
        ->and($shop->conditional_starts_at)->toBeNull()
        ->and($shop->conditional_ends_at)->toBeNull()
        ->and($shop->promotion_starts_at)->toBeNull()
        ->and($shop->promotion_ends_at)->toBeNull()
        ->and($shop->promotion_label)->toBeNull()
        ->and($shop->last_success_at)->toBeNull()
        ->and($shop->last_checked_at)->toBeNull();
});

test('a repointed offer loses cheapest instead of carrying the old price over', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $rival = Shop::factory()->for($product)->create([
        'url' => 'https://rival.example.com/p/1',
        'current_price' => '12.00',
    ]);
    $repointed = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '5.00',
    ]);

    $product->recomputeCheapestShop();
    expect($product->cheapest_shop_id)->toBe($repointed->id);

    $repointed->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/2'));

    $product->recomputeCheapestShop();

    expect($product->cheapest_shop_id)->toBe($rival->id)
        ->and((string) $product->cheapest_price)->toBe('12.00');
});

test('a no-op url edit leaves the offer state intact', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '10.00',
        'conditional_price' => '8.00',
        'promotion_label' => 'Two for one',
    ]);

    expect($shop->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/1')))->toBeFalse();

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('10.00')
        ->and((string) $shop->conditional_price)->toBe('8.00')
        ->and($shop->promotion_label)->toBe('Two for one');
});
