<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Support\BundlePriceLabel;

test('bundle columns cast and reconstruct on persisted records', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create([
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);
    $check = PriceCheck::factory()->for($shop)->create([
        'price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);
    $history = ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);

    expect($shop->singleItemPrice())->toBe('2.85')
        ->and($shop->bundleOffer()?->effectiveUnitPrice())->toBe('2.00')
        ->and($shop->liveBundleOffer()?->totalPrice)->toBe('4.00')
        ->and($check->single_item_price)->toBe('2.85')
        ->and($check->bundle_quantity)->toBe(2)
        ->and($history->singleItemPrice())->toBe('2.85')
        ->and($history->bundleOffer()?->quantity)->toBe(2);
});

test('legacy and partial rows use safe fallbacks', function (): void {
    $shop = Shop::factory()->create([
        'current_price' => '3.25',
        'single_item_price' => null,
        'bundle_quantity' => 2,
        'bundle_total_price' => null,
    ]);
    $history = ProductCheapestHistory::factory()->create([
        'cheapest_price' => '3.25',
        'single_item_price' => null,
        'bundle_quantity' => null,
        'bundle_total_price' => '5.00',
    ]);

    expect($shop->singleItemPrice())->toBe('3.25')
        ->and($shop->bundleOffer())->toBeNull()
        ->and($history->singleItemPrice())->toBe('3.25')
        ->and($history->bundleOffer())->toBeNull();
});

test('persisted no-saving bundle terms use scalar-price fallbacks', function (): void {
    $shop = Shop::factory()->create([
        'current_price' => '2.85',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '6.00',
    ]);
    $check = PriceCheck::factory()->for($shop)->create([
        'price' => '2.85',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '6.00',
    ]);
    $history = ProductCheapestHistory::factory()->create([
        'cheapest_price' => '2.85',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '6.00',
    ]);

    expect($shop->bundleOffer())->toBeNull()
        ->and($check->bundleOffer())->toBeNull()
        ->and($history->bundleOffer())->toBeNull();
});

test('expired stored bundle stays disclosed until tracked price is restored', function (): void {
    $shop = Shop::factory()->create([
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'promotion_ends_at' => now()->subMinute(),
    ]);

    expect($shop->bundleOffer())->not->toBeNull()
        ->and($shop->liveBundleOffer())->not->toBeNull()
        ->and(BundlePriceLabel::forShop($shop))->toContain('ended');

    $shop->forceFill(['current_price' => '2.85'])->save();

    expect($shop->fresh()->liveBundleOffer())->toBeNull();
});

test('bundle labels retain source wording and deadline from preview snapshots', function (): void {
    $label = BundlePriceLabel::forSnapshot([
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'single_item_price' => '2.85',
        'currency' => 'EUR',
        'promotion_starts_at' => now()->subDay()->toIso8601String(),
        'promotion_ends_at' => now()->addDay()->toIso8601String(),
        'promotion_label' => '2 VOOR 4.00',
    ]);

    expect($label)->toContain('2 VOOR 4.00 until')
        ->toContain('2 for €4.00')
        ->toContain('or €2.85 each')
        ->and(BundlePriceLabel::forSnapshot([
            'bundle_quantity' => 2,
            'bundle_total_price' => '4.00',
            'single_item_price' => '2.85',
            'currency' => 'EUR',
            'promotion_ends_at' => 'not-a-date',
        ]))->toBeNull();
});
