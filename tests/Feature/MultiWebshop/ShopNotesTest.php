<?php declare(strict_types=1);

use App\Models\Shop;

test('Shop.notes defaults to null and round-trips arbitrary text', function (): void {
    $shop = Shop::factory()->create();
    expect($shop->fresh()->notes)->toBeNull();

    $shop->update(['notes' => "ships only to NL\ncoupon CODE10 at checkout"]);

    expect($shop->fresh()->notes)->toBe("ships only to NL\ncoupon CODE10 at checkout");
});

test('Shop.notes accepts factory override', function (): void {
    $shop = Shop::factory()->create(['notes' => 'Watch for VAT differences.']);

    expect($shop->fresh()->notes)->toBe('Watch for VAT differences.');
});

test('clearing Shop.notes back to null persists', function (): void {
    $shop = Shop::factory()->create(['notes' => 'temporary note']);

    $shop->update(['notes' => null]);

    expect($shop->fresh()->notes)->toBeNull();
});
