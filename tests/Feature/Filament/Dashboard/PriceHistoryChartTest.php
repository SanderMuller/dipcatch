<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

function makeChartFor(Product $product, string $range = '90'): PriceHistoryChart
{
    $widget = new PriceHistoryChart();
    $widget->record = $product;
    $widget->filter = $range;

    return $widget;
}

/**
 * @return array<string, mixed>
 */
function chartData(Product $product, string $range = '90'): array
{
    return makeChartFor($product, $range)->computeData();
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function findDataset(array $data, string $label): array
{
    /** @var iterable<array<string, mixed>> $sets */
    $sets = is_array($data['datasets'] ?? null) ? $data['datasets'] : [];
    foreach ($sets as $set) {
        if (($set['label'] ?? null) === $label) {
            return $set;
        }
    }

    return ['data' => []];
}

function currentTestUser(): User
{
    /** @var User $user */
    $user = test()->user; // @phpstan-ignore-line property.internalClass

    return $user;
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('chart shows only Ok-status checks for the product, sorted by checked_at', function (): void {
    $product = Product::factory()->for(currentTestUser())->create([
        'initial_price' => '100.00',
        'currency' => 'EUR',
        'drop_threshold_pct' => '10',
        'drop_threshold_abs' => '5',
    ]);

    PriceCheck::factory()->for($product)->state([
        'price' => '95.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now()->subDays(2),
    ])->create();
    PriceCheck::factory()->for($product)->failed()->state([
        'checked_at' => CarbonImmutable::now()->subDay(),
    ])->create();
    PriceCheck::factory()->for($product)->state([
        'price' => '90.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now(),
    ])->create();

    $data = chartData($product);

    expect(findDataset($data, 'Price (EUR)')['data'])->toBe([95.0, 90.0]);
});

test('range filter limits checks to the selected window', function (): void {
    $product = Product::factory()->for(currentTestUser())->create();

    PriceCheck::factory()->for($product)->state([
        'price' => '50.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now()->subDays(45),
    ])->create();
    PriceCheck::factory()->for($product)->state([
        'price' => '40.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now()->subDays(2),
    ])->create();

    $thirtyDay = chartData($product, '30');
    $ninetyDay = chartData($product, '90');
    $label = 'Price (' . $product->currency . ')';

    expect(findDataset($thirtyDay, $label)['data'])->toBe([40.0])
        ->and(findDataset($ninetyDay, $label)['data'])->toBe([50.0, 40.0]);
});

test('overlay datasets render the initial line for every product', function (): void {
    $product = Product::factory()->for(currentTestUser())->create([
        'initial_price' => '100.00',
        'currency' => 'EUR',
    ]);
    PriceCheck::factory()->for($product)->state([
        'price' => '95.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now(),
    ])->create();

    $data = chartData($product);

    expect(findDataset($data, 'Initial')['data'])->toBe([100.0]);
});

test('reference line falls back to initial price when fewer than 7 samples', function (): void {
    $product = Product::factory()->for(currentTestUser())->create([
        'initial_price' => '50.00',
        'currency' => 'EUR',
    ]);

    PriceCheck::factory()->for($product)->count(3)->state([
        'price' => '45.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now(),
    ])->create();

    $data = chartData($product);

    expect(findDataset($data, 'Reference (30d median)')['data'][0])->toBe(50.0);
});

test('reference line uses the median when at least 7 samples exist', function (): void {
    $product = Product::factory()->for(currentTestUser())->create([
        'initial_price' => '500.00',
        'currency' => 'EUR',
    ]);

    foreach ([100, 110, 120, 130, 140, 150, 200] as $price) {
        PriceCheck::factory()->for($product)->state([
            'price' => (string) $price,
            'status' => ScrapeStatus::Ok,
            'checked_at' => CarbonImmutable::now()->subDays(1),
        ])->create();
    }

    $data = chartData($product);

    // Median of [100,110,120,130,140,150,200] = 130
    expect(findDataset($data, 'Reference (30d median)')['data'][0])->toBe(130.0);
});

test('threshold low overlay = reference - max(abs, ref * pct/100)', function (): void {
    $product = Product::factory()->for(currentTestUser())->create([
        'initial_price' => '100.00',
        'currency' => 'EUR',
        'drop_threshold_pct' => '10',  // 10% of 100 = 10
        'drop_threshold_abs' => '5',   // abs 5 < 10 so pct wins
    ]);

    PriceCheck::factory()->for($product)->state([
        'price' => '90.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now(),
    ])->create();

    $data = chartData($product);

    // Reference falls back to initial 100 (only 1 sample), so low = 100 - 10 = 90.
    expect(findDataset($data, 'Threshold low')['data'][0])->toBe(90.0);
});

test('notification markers come from price_drop_events joined by price_check_id', function (): void {
    $product = Product::factory()->for(currentTestUser())->create();

    PriceCheck::factory()->for($product)->state([
        'price' => '90.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now()->subDays(2),
    ])->create();
    $check2 = PriceCheck::factory()->for($product)->state([
        'price' => '80.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => CarbonImmutable::now(),
    ])->create();

    PriceDropEvent::factory()->state([
        'product_id' => $product->id,
        'user_id' => currentTestUser()->id,
        'price_check_id' => $check2->id,
        'currency' => $product->currency,
        'new_price' => '80.00',
        'fired_at' => CarbonImmutable::now(),
    ])->create();

    $data = chartData($product);
    $markers = findDataset($data, 'Notified');

    // Only the second check has a marker; first is null (no marker).
    expect($markers['data'])->toBe([null, 80.0]);
});

test('returns empty datasets when no record is set', function (): void {
    $widget = new PriceHistoryChart();
    expect($widget->computeData())->toBe(['datasets' => [], 'labels' => []]);
});
