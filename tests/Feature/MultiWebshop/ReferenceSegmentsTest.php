<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;

function seedSegment(Product $product, Shop $shop, string $price, int $startsHoursAgo, ?int $endsHoursAgo): void
{
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => $price,
        'started_at' => now()->subHours($startsHoursAgo),
        'ended_at' => $endsHoursAgo === null ? null : now()->subHours($endsHoursAgo),
    ]);
}

function seedSuccessfulChecks(Shop $shop, int $count, int $spreadHours = 24): void
{
    foreach (range(1, $count) as $i) {
        PriceCheck::factory()->for($shop)->create([
            'status' => ScrapeStatus::Ok,
            'checked_at' => now()->subHours((int) ($spreadHours * ($i / max($count, 1)))),
        ]);
    }
}

test('returns null when product has no history', function (): void {
    $product = Product::factory()->create();

    expect(app(Reference::class)->compute($product))->toBeNull();
});

test('falls back to earliest segment price when fewer than 7 successful checks in window', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    seedSegment($product, $shop, '199.00', 24, null);
    seedSuccessfulChecks($shop, 3); // below threshold

    $ref = app(Reference::class)->compute($product);

    expect($ref)->toBeInstanceOf(ReferenceValue::class)
        ->and($ref->value)->toBe('199.00')
        ->and($ref->kind)->toBe(ReferenceValue::KIND_INITIAL);
});

test('time-weighted median: long segment dominates short blip', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // Seven historical segments: six at 100 (long), one short blip at 50.
    foreach (range(1, 6) as $i) {
        $start = $i * 100;
        $end = ($i - 1) * 100 + 1;
        seedSegment($product, $shop, '100.00', $start, $end);
    }
    seedSegment($product, $shop, '50.00', 1, null); // short open segment

    // Seven successful checks within the window to unlock the median gate.
    seedSuccessfulChecks($shop, 7);

    $ref = app(Reference::class)->compute($product);

    expect($ref)->toBeInstanceOf(ReferenceValue::class)
        ->and($ref->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and($ref->value)->toBe('100.00');
});

test('ignores segments fully before the 30-day window', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // Segment fully before window: starts 40 days ago, ends 35 days ago.
    seedSegment($product, $shop, '500.00', 40 * 24, 35 * 24);
    // One recent segment.
    seedSegment($product, $shop, '100.00', 24, null);

    $ref = app(Reference::class)->compute($product);

    // No in-window checks → fall back to earliest of all segments.
    expect($ref->value)->toBe('500.00')
        ->and($ref->kind)->toBe(ReferenceValue::KIND_INITIAL);
});

test('open segment contributes weight up to now', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // 7 segments — one open spanning a day; others tiny.
    seedSegment($product, $shop, '80.00', 24, null);
    foreach (range(1, 6) as $i) {
        $end = $i;
        $start = $i + 1;
        seedSegment($product, $shop, '200.00', $start, $end);
    }

    seedSuccessfulChecks($shop, 7);

    $ref = app(Reference::class)->compute($product);

    expect($ref->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and($ref->value)->toBe('80.00'); // the open segment dominates by weight
});

test('stable low-volatility product graduates from initial to median once enough checks accumulate', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    // One long open segment — pre-fix this would have been only 1 sample and
    // never escaped INITIAL even with dozens of checks.
    seedSegment($product, $shop, '120.00', 25 * 24, null);
    seedSuccessfulChecks($shop, 12, spreadHours: 25 * 24);

    $ref = app(Reference::class)->compute($product);

    expect($ref)->toBeInstanceOf(ReferenceValue::class)
        ->and($ref->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and($ref->value)->toBe('120.00')
        ->and($ref->sampleSize)->toBe(12);
});

test('failed checks do not count toward the median gate', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    seedSegment($product, $shop, '60.00', 25 * 24, null);

    // 10 failed checks — should not graduate to median_30d.
    foreach (range(1, 10) as $i) {
        PriceCheck::factory()->for($shop)->failed()->create([
            'checked_at' => now()->subHours($i),
        ]);
    }

    $ref = app(Reference::class)->compute($product);

    expect($ref->kind)->toBe(ReferenceValue::KIND_INITIAL);
});
