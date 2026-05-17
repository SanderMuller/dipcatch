<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;

function makeChartFor(Product $product, string $range = '90'): PriceHistoryChart
{
    $widget = new PriceHistoryChart();
    $widget->record = $product;
    $widget->filter = $range;

    return $widget;
}

test('empty product returns empty labels', function (): void {
    $product = Product::factory()->create();

    $data = makeChartFor($product)->computeData();

    expect($data['labels'])->toBe([]);
});

test('renders cheapest segments as a stepped line', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'started_at' => now()->subDays(20),
        'ended_at' => now()->subDays(10),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    $data = makeChartFor($product)->computeData();

    /** @var list<array<string, mixed>> $datasets */
    $datasets = $data['datasets'];
    $cheapest = collect($datasets)->firstWhere('label', 'Cheapest (EUR)');
    assert(is_array($cheapest));

    expect($cheapest['data'])->toContain(100.0)
        ->and($cheapest['data'])->toContain(85.0)
        ->and($cheapest['stepped'])->toBeTrue();
});

test('respects the range filter', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '500.00',
        'started_at' => now()->subDays(120),
        'ended_at' => now()->subDays(115),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '50.00',
        'started_at' => now()->subDays(2),
        'ended_at' => null,
    ]);

    $thirtyDay = makeChartFor($product, '30')->computeData();
    /** @var list<array<string, mixed>> $datasets */
    $datasets = $thirtyDay['datasets'];
    $cheapest = collect($datasets)->first(static function (array $set): bool {
        $label = $set['label'] ?? null;

        return is_string($label) && str_starts_with($label, 'Cheapest');
    });
    assert(is_array($cheapest));

    expect($cheapest['data'])->not->toContain(500.0)
        ->and($cheapest['data'])->toContain(50.0);
});

test('notification markers are scoped to the active range filter', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create();

    // Two segments so each event lands on its own (notificationMarkers
    // collapses multiple events on the same segment to the last one — pre-
    // existing limitation, not what this test asserts about).
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '60.00',
        'started_at' => now()->subDays(120),
        'ended_at' => now()->subDays(90),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '50.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    // Old drop event on the old segment (outside 30-day window).
    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $product->user_id,
        'fired_at' => now()->subDays(100),
        'new_price' => '60.00',
    ]);

    // Recent drop event on the current segment (inside 30-day window).
    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $product->user_id,
        'fired_at' => now()->subDays(5),
        'new_price' => '50.00',
    ]);

    $thirtyDay = makeChartFor($product, '30')->computeData();
    /** @var list<array<string, mixed>> $datasets */
    $datasets = $thirtyDay['datasets'];
    $notified = collect($datasets)->firstWhere('label', 'Notified');
    assert(is_array($notified));

    // Only the recent (50.00) marker should be present; the old 60.00 must
    // not leak in even though the segment it sits on extends back 120 days.
    expect($notified['data'])->toContain(50.0)
        ->and($notified['data'])->not->toContain(60.0);

    // Sanity: "All time" still includes both.
    $allTime = makeChartFor($product, 'all')->computeData();
    /** @var list<array<string, mixed>> $datasetsAll */
    $datasetsAll = $allTime['datasets'];
    $notifiedAll = collect($datasetsAll)->firstWhere('label', 'Notified');
    assert(is_array($notifiedAll));
    expect($notifiedAll['data'])->toContain(50.0)
        ->and($notifiedAll['data'])->toContain(60.0);
});
