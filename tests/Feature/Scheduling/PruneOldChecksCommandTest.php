<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;

test('prunes price_checks per offer older than retention when more than 50 rows exist', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // 60 old checks → only 50 most recent kept.
    for ($i = 0; $i < 60; $i++) {
        PriceCheck::factory()->for($shop)->create([
            'checked_at' => now()->subDays(400 + $i),
        ]);
    }

    // 5 recent checks → always survive.
    $recent = PriceCheck::factory()->for($shop)->count(5)->create([
        'checked_at' => now()->subDays(10),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(50);
    foreach ($recent as $r) {
        expect(PriceCheck::query()->whereKey($r->id)->exists())->toBeTrue();
    }
});

test('keeps last N per offer even when all are old', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    PriceCheck::factory()->for($shop)->count(40)->create([
        'checked_at' => now()->subDays(500),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(40);
});

test('prunes price_drop_events with the same retention rule', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();
    $check = PriceCheck::factory()->for($shop)->create(['checked_at' => now()->subDays(10)]);

    PriceDropEvent::factory()->for($product)->count(60)->create([
        'price_check_id' => $check->id,
        'user_id' => $product->user_id,
        'fired_at' => now()->subDays(500),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(50);
});

test('prunes closed cheapest_history segments older than the retention window', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // 3 closed segments well past retention.
    foreach (range(1, 3) as $i) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'cheapest_price' => '100.00',
            'started_at' => now()->subDays(500 + $i),
            'ended_at' => now()->subDays(499 + $i),
        ]);
    }

    // Current open segment — never prune.
    $open = ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '90.00',
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(ProductCheapestHistory::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductCheapestHistory::query()->whereKey($open->id)->exists())->toBeTrue();
});

test('protects price_checks referenced by legacy drop events with NULL triggered_by_shop_id', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // Old check that the retention window alone would prune.
    $oldCheck = PriceCheck::factory()->for($shop)->create([
        'checked_at' => now()->subDays(500),
    ]);

    // Pad the offer with > 50 newer checks so the "last 50" protection
    // doesn't accidentally save the old one.
    PriceCheck::factory()->for($shop)->count(60)->create([
        'checked_at' => now()->subDays(10),
    ]);

    // Legacy event: triggered_by_shop_id NULL but price_check_id points
    // at the old check. The retained event (newest 50 keeps it) anchors to it.
    PriceDropEvent::factory()->for($product)->create([
        'price_check_id' => $oldCheck->id,
        'triggered_by_shop_id' => null,
        'user_id' => $product->user_id,
        'fired_at' => now()->subDays(5),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->whereKey($oldCheck->id)->exists())->toBeTrue();
});

test('does not delete recent checks for an offer with few rows', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();
    PriceCheck::factory()->for($shop)->count(3)->create(['checked_at' => now()->subDays(5)]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(3);
});
