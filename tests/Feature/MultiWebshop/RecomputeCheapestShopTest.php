<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;

test('equal-priced offers tie-break deterministically and recompute is idempotent', function (): void {
    $product = Product::factory()->create();
    // Pin created_at + id so the assertion is independent of factory ordering.
    $a = Shop::factory()->for($product)->create([
        'id' => '00000000-0000-0000-0000-000000000001',
        'current_price' => '40.00',
        'created_at' => now()->subMinutes(3),
    ]);
    Shop::factory()->for($product)->create([
        'id' => '00000000-0000-0000-0000-000000000002',
        'current_price' => '40.00',
        'created_at' => now()->subMinutes(2),
    ]);
    Shop::factory()->for($product)->create([
        'id' => '00000000-0000-0000-0000-000000000003',
        'current_price' => '40.00',
        'created_at' => now()->subMinute(),
    ]);

    $product->recomputeCheapestShop();
    $first = $product->cheapest_shop_id;

    $product->recomputeCheapestShop();
    $product->recomputeCheapestShop();

    expect($first)->toBe($a->id)
        ->and($product->cheapest_shop_id)->toBe($a->id)
        ->and(ProductCheapestHistory::query()
            ->where('product_id', $product->id)
            ->whereNull('ended_at')
            ->count())->toBe(1);
});

test('picks the lowest active in-stock non-dead offer', function (): void {
    $product = Product::factory()->create();
    $a = Shop::factory()->for($product)->create(['current_price' => '100.00']);
    $b = Shop::factory()->for($product)->create(['current_price' => '80.00']);
    Shop::factory()->for($product)->inactive()->create(['current_price' => '50.00']);
    Shop::factory()->for($product)->outOfStock()->create(['current_price' => '40.00']);
    Shop::factory()->for($product)->dead()->create(['current_price' => '30.00']);

    $product->recomputeCheapestShop();

    expect((string) $product->cheapest_price)->toBe('80.00')
        ->and($product->cheapest_shop_id)->toBe($b->id)
        ->and($a->id)->not->toBe($product->cheapest_shop_id);
});

test('writes a history segment when cheapest changes', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['current_price' => '100.00']);

    $product->recomputeCheapestShop();

    expect($product->cheapestHistory()->count())->toBe(1);

    // Add a cheaper offer → new segment.
    Shop::factory()->for($product)->create(['current_price' => '80.00']);
    $product->recomputeCheapestShop();

    expect($product->cheapestHistory()->count())->toBe(2);

    /** @var ProductCheapestHistory $closed */
    $closed = $product->cheapestHistory()->oldest('started_at')->first();
    expect($closed->ended_at)->not->toBeNull();

    /** @var ProductCheapestHistory $open */
    $open = $product->cheapestHistory()->latest('started_at')->first();
    expect($open->ended_at)->toBeNull()
        ->and((string) $open->cheapest_price)->toBe('80.00');
});

test('does not write a new segment when nothing changed', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['current_price' => '100.00']);

    $product->recomputeCheapestShop();
    $first = $product->cheapestHistory()->count();
    $product->recomputeCheapestShop();

    expect($product->cheapestHistory()->count())->toBe($first);
});

test('clears cheapest when all offers become ineligible', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create(['current_price' => '100.00']);
    $product->recomputeCheapestShop();

    $shop->update(['current_in_stock' => false]);
    $product->recomputeCheapestShop();

    expect($product->cheapest_shop_id)->toBeNull()
        ->and($product->cheapest_price)->toBeNull();

    /** @var ProductCheapestHistory $latest */
    $latest = $product->cheapestHistory()->latest('started_at')->first();
    expect($latest->cheapest_price)->toBeNull()
        ->and($latest->ended_at)->toBeNull();
});

test('upward move clears the latch when new cheapest meets reference', function (): void {
    // Product already has a populated cheapest_price + an armed latch.
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create(['current_price' => '60.00']);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '60.00',
        'last_notified_price' => '50.00',
        'last_notified_at' => now()->subHour(),
    ])->save();

    // Seed a history segment so Reference can compute (falls back to initial).
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '60.00',
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);

    $shop->update(['current_price' => '95.00']);
    $product->recomputeCheapestShop();

    expect($product->last_notified_price)->toBeNull()
        ->and($product->last_notified_at)->toBeNull();
});
