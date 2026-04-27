<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;

test('prunes price_checks older than 365 days when more than 50 rows exist', function (): void {
    $product = Product::factory()->create();

    // 60 old checks (older than 365 days) — only 50 most recent should be kept.
    $old = collect();
    for ($i = 0; $i < 60; $i++) {
        $old->push(PriceCheck::factory()->for($product)->create([
            'checked_at' => now()->subDays(400 + $i),
        ]));
    }

    // 5 recent checks (within 365 days) — must always survive.
    $recent = PriceCheck::factory()->for($product)->count(5)->create([
        'checked_at' => now()->subDays(10),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    // 5 recent (always kept) + 45 most-recent old (in top-50) = 50 retained.
    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(50);
    foreach ($recent as $r) {
        expect(PriceCheck::query()->whereKey($r->id)->exists())->toBeTrue();
    }
});

test('keeps last 50 per product even when all are older than 365 days', function (): void {
    $product = Product::factory()->create();

    PriceCheck::factory()->for($product)->count(40)->create([
        'checked_at' => now()->subDays(500),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(40);
});

test('prunes price_drop_events with same retention rules', function (): void {
    $product = Product::factory()->create();
    $check = PriceCheck::factory()->for($product)->create([
        'checked_at' => now()->subDays(10),
    ]);

    PriceDropEvent::factory()->for($product)->count(60)->create([
        'price_check_id' => $check->id,
        'user_id' => $product->user_id,
        'fired_at' => now()->subDays(500),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(50);
});

test('does not delete recent checks for a product with few rows', function (): void {
    $product = Product::factory()->create();
    PriceCheck::factory()->for($product)->count(3)->create([
        'checked_at' => now()->subDays(5),
    ]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(3);
});
