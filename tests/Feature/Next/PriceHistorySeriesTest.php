<?php declare(strict_types=1);

use App\Charts\PriceHistorySeries;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use Carbon\CarbonImmutable;

/**
 * The dataset a chart plots under a given legend label.
 *
 * @return array<string, mixed>
 */
function chartSeries(Product $product, string $label, string $range = '90'): array
{
    foreach (makeChartFor($product, $range)->data()['datasets'] as $dataset) {
        if (($dataset['label'] ?? null) === $label) {
            return $dataset;
        }
    }

    return [];
}

function makeChartFor(Product $product, string $range = '90'): PriceHistorySeries
{
    return new PriceHistorySeries($product, $range);
}

test('empty product returns empty labels', function (): void {
    $product = Product::factory()->create();

    $data = makeChartFor($product)->data();

    expect($data['labels'])->toBeEmpty();
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

    $data = makeChartFor($product)->data();

    /** @var list<array<string, mixed>> $datasets */
    $datasets = $data['datasets'];
    $cheapest = collect($datasets)->firstWhere('label', 'Cheapest (€)');
    assert(is_array($cheapest));

    expect($cheapest['data'])->toContain(100.0)
        ->and($cheapest['data'])->toContain(85.0)
        ->and($cheapest['stepped'])->toBeTrue();

    $flux = makeChartFor($product)->fluxChart();

    expect($flux['rows'])->not->toBeEmpty()
        ->and(collect($flux['rows'])->pluck('price')->all())->toContain(100.0, 85.0)
        ->and($flux['currency'])->toBe('EUR');

    $lastHundred = collect($flux['rows'])->last(fn (array $row): bool => ($row['price'] ?? null) === 100.0);
    $firstDrop = collect($flux['rows'])->first(fn (array $row): bool => ($row['price'] ?? null) === 85.0);
    $lastDate = is_array($lastHundred) ? ($lastHundred['date'] ?? null) : null;
    $firstDate = is_array($firstDrop) ? ($firstDrop['date'] ?? null) : null;

    expect($lastDate)->toBeString()
        ->and($firstDate)->toBeString();

    if (! is_string($lastDate) || ! is_string($firstDate)) {
        return;
    }

    expect(CarbonImmutable::parse($lastDate)->diffInSeconds(CarbonImmutable::parse($firstDate), true))
        ->toBeLessThanOrEqual(1);
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

    $thirtyDay = makeChartFor($product, '30')->data();
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

    // The owner needs Pro for "All time" to mean all time: a free account's
    // window is capped at its plan's history days, which is what the second
    // half of this test would otherwise be measuring.
    subscribeUser($product->user()->sole());

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

    $thirtyDay = makeChartFor($product, '30')->data();
    /** @var list<array<string, mixed>> $datasets */
    $datasets = $thirtyDay['datasets'];
    $notified = collect($datasets)->firstWhere('label', 'Notified');
    assert(is_array($notified));

    // Only the recent (50.00) marker should be present; the old 60.00 must
    // not leak in even though the segment it sits on extends back 120 days.
    expect($notified['data'])->toContain(50.0)
        ->and($notified['data'])->not->toContain(60.0);

    // Sanity: "All time" still includes both.
    $allTime = makeChartFor($product, 'all')->data();
    /** @var list<array<string, mixed>> $datasetsAll */
    $datasetsAll = $allTime['datasets'];
    $notifiedAll = collect($datasetsAll)->firstWhere('label', 'Notified');
    assert(is_array($notifiedAll));
    expect($notifiedAll['data'])->toContain(50.0)
        ->and($notifiedAll['data'])->toContain(60.0);
});

test('the per-unit series is omitted when the range in view shows no unit data', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $plain = Shop::factory()->for($product)->create([
        'url' => 'https://plain.test/p/1', 'pack_quantity' => null, 'pack_unit' => null,
    ]);
    $perKilo = Shop::factory()->for($product)->create([
        'url' => 'https://kilo.test/p/1', 'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $perKilo->id,
        'cheapest_price' => '2.00',
        'started_at' => now()->subDays(400),
        'ended_at' => now()->subDays(300),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $plain->id,
        'cheapest_price' => '3.00',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    $unitSeries = array_filter(
        makeChartFor($product, '30')->data()['datasets'],
        fn (array $dataset): bool => ($dataset['yAxisID'] ?? null) === 'unit',
    );

    expect($unitSeries)->toBeEmpty();
});

test('the cheapest price is plotted per unit as well, on its own axis', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['pack_quantity' => '200.00', 'pack_unit' => 'g']);

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '2.19',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    $unit = chartSeries($product, 'Cheapest per kg (€)');
    $flux = makeChartFor($product)->fluxChart();

    expect($unit)->not->toBeEmpty()
        ->and($unit['data'])->toBe([10.95, 10.95])
        ->and($unit['yAxisID'])->toBe('unit')
        ->and($flux['unitLabel'])->toBe('Cheapest per kg (€)');
});

test('a cheaper total that is worse value shows as two diverging lines', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $small = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $large = Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1', 'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    // The price falls while the value gets worse: a smaller bag, cheaper.
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $large->id,
        'cheapest_price' => '1.99',
        'started_at' => now()->subDays(5),
        'ended_at' => now()->subDay(),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $small->id,
        'cheapest_price' => '1.69',
        'started_at' => now()->subDay(),
        'ended_at' => null,
    ]);

    expect(chartSeries($product, 'Cheapest (€)')['data'])->toBe([1.99, 1.69, 1.69])
        // Down in euros, up per kilo — the point of the second line.
        ->and(chartSeries($product, 'Cheapest per kg (€)')['data'])->toBe([5.38, 8.45, 8.45]);
});

test('shops that state no pack size get no unit line', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['pack_quantity' => null, 'pack_unit' => null]);

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '2.19',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    expect(chartSeries($product, 'Cheapest per kg (€)'))->toBeEmpty();
});

test('bundle history rows carry purchase condition into chart tooltip data', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'started_at' => now()->subDay(),
        'ended_at' => null,
    ]);

    $chart = makeChartFor($product)->fluxChart();

    expect($chart['hasBundles'])->toBeTrue()
        ->and($chart['rows'][0]['bundle'])->toBe('2 for €4.00 · Single item €2.85');
});

test('units that cannot share an axis leave gaps rather than wrong numbers', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $perKilo = Shop::factory()->for($product)->create([
        'url' => 'https://a.test/p/1', 'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $perPiece = Shop::factory()->for($product)->create([
        'url' => 'https://b.test/p/1', 'pack_quantity' => '4.00', 'pack_unit' => 'piece',
    ]);

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $perPiece->id,
        'cheapest_price' => '2.00',
        'started_at' => now()->subDays(5),
        'ended_at' => now()->subDays(3),
    ]);
    foreach ([now()->subDays(3), now()->subDay()] as $index => $startedAt) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $perKilo->id,
            'cheapest_price' => '2.19',
            'started_at' => $startedAt,
            'ended_at' => $index === 0 ? now()->subDay() : null,
        ]);
    }

    // The per-piece segment is not a EUR/kg number, so it is a gap.
    expect(chartSeries($product, 'Cheapest per kg (€)')['data'])->toBe([null, 10.95, 10.95, 10.95]);
});
