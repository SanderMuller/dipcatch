<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use Filament\Support\RawJs;

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
    $cheapest = collect($datasets)->firstWhere('label', 'Cheapest (€)');
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

test('chart options carry the currency-aware tooltip formatter', function (): void {
    $options = (new ReflectionMethod(PriceHistoryChart::class, 'getOptions'))->invoke(new PriceHistoryChart());

    expect($options)->toBeInstanceOf(RawJs::class);
    assert($options instanceof RawJs);

    expect($options->toHtml())
        ->toContain('Intl.NumberFormat')
        ->toContain('ctx.dataset.currency');
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

    $datasets = makeChartFor($product)->computeData()['datasets'];
    $unit = collect($datasets)->firstWhere('label', 'Cheapest per kg (€)');

    expect($unit)->not->toBeNull()
        ->and($unit['data'])->toBe([10.95, 10.95])
        ->and($unit['yAxisID'])->toBe('unit');
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

    $datasets = collect(makeChartFor($product)->computeData()['datasets']);

    expect($datasets->firstWhere('label', 'Cheapest (€)')['data'])->toBe([1.99, 1.69, 1.69])
        // Down in euros, up per kilo — the point of the second line.
        ->and($datasets->firstWhere('label', 'Cheapest per kg (€)')['data'])->toBe([5.38, 8.45, 8.45]);
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

    $labels = collect(makeChartFor($product)->computeData()['datasets'])->pluck('label');

    expect($labels)->not->toContain('Cheapest per kg (€)');
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

    $unit = collect(makeChartFor($product)->computeData()['datasets'])->firstWhere('label', 'Cheapest per kg (€)');

    // The per-piece segment is not a EUR/kg number, so it is a gap.
    expect($unit['data'])->toBe([null, 10.95, 10.95, 10.95]);
});
