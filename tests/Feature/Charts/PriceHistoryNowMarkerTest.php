<?php declare(strict_types=1);

use App\Charts\PriceHistoryFluxChart;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\User;

use function Pest\Livewire\livewire;

/**
 * Chart data as PriceHistorySeries::data() hands it over, pack prices only.
 *
 * @param  array<string, float|null>  $prices  stamp => price
 * @return array{labels: list<string>, price: list<float|null>, unit: null, notified: list<float|null>, bundleConditions: list<?string>}
 */
function nowMarkerData(array $prices): array
{
    return [
        'labels' => array_keys($prices),
        'price' => array_values($prices),
        'unit' => null,
        'notified' => array_fill(0, count($prices), null),
        'bundleConditions' => array_fill(0, count($prices), null),
    ];
}

it('marks the newest reading as now, and names a fall within the last day', function (): void {
    $chart = PriceHistoryFluxChart::fromData(nowMarkerData([
        now()->subDays(10)->format('Y-m-d H:i:s') => 647.0,
        now()->subDays(3)->format('Y-m-d H:i:s') => 629.0,
        now()->subHours(2)->format('Y-m-d H:i:s') => 587.0,
    ]), 'EUR');

    expect($chart['latest']['price'])->toBe(['value' => 587.0, 'dropToday' => 7])
        ->and($chart['latest']['unit'])->toBeNull()
        ->and(array_last($chart['rows'])['now'] ?? null)->toBe(587.0)
        // Only the newest row carries the point.
        ->and(array_filter($chart['rows'], fn (array $row): bool => isset($row['now'])))->toHaveCount(1);
});

it('names no drop when the last change is older than a day, or a rise', function (float $last, string $changedAgo): void {
    $chart = PriceHistoryFluxChart::fromData(nowMarkerData([
        now()->subDays(10)->format('Y-m-d H:i:s') => 600.0,
        now()->sub($changedAgo)->format('Y-m-d H:i:s') => $last,
        now()->subMinutes(5)->format('Y-m-d H:i:s') => $last,
    ]), 'EUR');

    expect($chart['latest']['price'])->toBe(['value' => $last, 'dropToday' => null]);
})->with([
    'a fall two days ago' => [550.0, '2 days'],
    'a rise today' => [650.0, '3 hours'],
]);

it('marks the per-unit line too', function (): void {
    $data = nowMarkerData([
        now()->subDays(3)->format('Y-m-d H:i:s') => 30.0,
        now()->subHour()->format('Y-m-d H:i:s') => 24.0,
    ]);
    $data['unit'] = ['unit' => 'ml', 'points' => [15.0, 12.0]];

    $chart = PriceHistoryFluxChart::fromData($data, 'EUR');

    expect($chart['latest']['unit'])->toBe(['value' => 12.0, 'dropToday' => 20])
        ->and(array_last($chart['rows'])['nowUnit'] ?? null)->toBe(12.0);
});

it('names no drop when a gap comes between the old price and today\'s', function (): void {
    $chart = PriceHistoryFluxChart::fromData(nowMarkerData([
        now()->subDays(5)->format('Y-m-d H:i:s') => 600.0,
        now()->subDays(2)->format('Y-m-d H:i:s') => null,
        now()->subHour()->format('Y-m-d H:i:s') => 550.0,
    ]), 'EUR');

    expect($chart['latest']['price'])->toBe(['value' => 550.0, 'dropToday' => null]);
});

it('puts no now point on a line whose newest reading is a gap', function (): void {
    $chart = PriceHistoryFluxChart::fromData(nowMarkerData([
        now()->subDays(2)->format('Y-m-d H:i:s') => 600.0,
        now()->subHour()->format('Y-m-d H:i:s') => null,
    ]), 'EUR');

    expect($chart['latest']['price'])->toBeNull()
        ->and(array_filter($chart['rows'], fn (array $row): bool => isset($row['now'])))->toBeEmpty();
});

it('labels where the price line ends on the product page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'cheapest_price' => '85.00']);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeHtml('data-test="now-callout-price"')
        ->assertSeeInOrder(['data-test="now-callout-price"', 'Now', '€85.00'], escape: false);
});

it('says how much the price fell today on the product page', function (): void {
    $this->travelTo(now('Europe/Amsterdam')->setTime(15, 0));
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'cheapest_price' => '85.00']);
    ProductCheapestHistory::factory()->for($product)->create(['cheapest_shop_id' => null, 'cheapest_price' => '100.00', 'started_at' => now()->subDays(10), 'ended_at' => now()->subHours(2)]);
    ProductCheapestHistory::factory()->for($product)->create(['cheapest_shop_id' => null, 'cheapest_price' => '85.00', 'started_at' => now()->subHours(2), 'ended_at' => null]);
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeInOrder(['data-test="now-callout-price"', 'Now', '15% lower today', '€85.00'], escape: false);
});
