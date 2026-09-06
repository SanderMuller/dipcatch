<?php declare(strict_types=1);

use App\Billing\HistoryWindow;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * A product whose cheapest price changed 200 days ago and again yesterday,
 * so a 90-day window sees one segment and a longer window sees both.
 */
function productWithOldHistory(User $user): Product
{
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $shop = Shop::factory()->create(['product_id' => $product->id, 'current_price' => '1.99']);

    ProductCheapestHistory::create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '9.99',
        'started_at' => CarbonImmutable::now()->subDays(200),
        'ended_at' => CarbonImmutable::now()->subDays(150),
    ]);

    ProductCheapestHistory::create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '1.99',
        'started_at' => CarbonImmutable::now()->subDay(),
        'ended_at' => null,
    ]);

    return $product->refresh();
}

function chartFor(Product $product, string $filter): PriceHistoryChart
{
    $chart = new PriceHistoryChart();
    $chart->record = $product;
    $chart->filter = $filter;

    return $chart;
}

/**
 * The price of the oldest point the chart plotted, or null when the long
 * segment is outside the window.
 */
function plotsTheOldSegment(PriceHistoryChart $chart): bool
{
    $data = $chart->computeData();
    $prices = $data['datasets'][0]['data'] ?? [];

    foreach ((array) $prices as $price) {
        if (is_numeric($price) && abs((float) $price - 9.99) < 0.001) {
            return true;
        }
    }

    return false;
}

it('offers a free account only the ranges it may read', function (): void {
    expect(array_keys(HistoryWindow::filters(90)))->toBe([30, 90])
        ->and(array_keys(HistoryWindow::filters(maxDays: null)))->toBe([30, 90, 365, 'all']);
});

it('clamps a tampered filter to the plan ceiling', function (string $filter): void {
    // `$filter` is a public Livewire property, so this is the value a free
    // account can post. The menu is not the enforcement point.
    $start = HistoryWindow::start(90, $filter);

    expect($start)->not->toBeNull()
        ->and($start->diffInDays(CarbonImmutable::now()))->toBeLessThanOrEqual(91);
})->with(['all', '365', '99999']);

it('lets an unlimited plan reach all time', function (): void {
    expect(HistoryWindow::start(maxDays: null, filter: 'all'))->toBeNull()
        ->and(HistoryWindow::start(maxDays: null, filter: '365'))->not->toBeNull();
});

it('hides a segment older than the free window from the chart', function (): void {
    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    expect(plotsTheOldSegment(chartFor($product, '90')))->toBeFalse()
        // Even asking for everything, a free account gets its 90 days.
        ->and(plotsTheOldSegment(chartFor($product, 'all')))->toBeFalse();
});

it('shows that same segment to a pro account', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    expect(plotsTheOldSegment(chartFor($product, 'all')))->toBeTrue()
        ->and(plotsTheOldSegment(chartFor($product, '365')))->toBeTrue();
});

it('reveals stored history the moment an account upgrades', function (): void {
    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    expect(plotsTheOldSegment(chartFor($product, 'all')))->toBeFalse();

    // Nothing is backfilled: free retention already kept the data.
    subscribeUser($user);

    expect(plotsTheOldSegment(chartFor($product->refresh(), 'all')))->toBeTrue();
});

it('clamps the notification markers to the same window as the line', function (): void {
    $user = User::factory()->create();
    $product = productWithOldHistory($user);

    PriceDropEvent::factory()->create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'fired_at' => CarbonImmutable::now()->subDays(180),
    ]);

    $this->actingAs($user);

    $markers = chartFor($product, 'all')->computeData()['datasets'][2]['data'] ?? [];

    expect(array_filter((array) $markers, static fn (mixed $m): bool => $m !== null))->toBe([]);
});

it('tells a free account why the long ranges are missing', function (): void {
    configureStripe();

    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(PriceHistoryChart::class, ['record' => $product, 'pageClass' => ViewProduct::class])
        ->assertSee('Your plan shows the last 90 days')
        ->assertSee('Compare plans');
});

it('does not advertise pro while the shop is shut', function (): void {
    // Nothing is on sale, so the reason stays and the link goes.
    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(PriceHistoryChart::class, ['record' => $product, 'pageClass' => ViewProduct::class])
        ->assertSee('Your plan shows the last 90 days')
        ->assertDontSee('Compare plans');
});

it('says nothing about plans to an account with no ceiling', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = productWithOldHistory($user);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(PriceHistoryChart::class, ['record' => $product, 'pageClass' => ViewProduct::class])
        ->assertDontSee('Your plan shows the last');
});

it('keeps the public shared page at 90 days for a pro owner', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = productWithOldHistory($user);
    $product->forceFill(['share_slug' => str_repeat('a', 32)])->save();

    $this->get(route('product.public', ['slug' => $product->share_slug]))
        ->assertOk()
        // The 200-day-old price must not appear on a public link.
        ->assertDontSee('9.99');
});

it('falls back to the free ceiling when the owner cannot be identified', function (): void {
    // A gate that opens when it cannot tell who is asking is not a gate.
    $chart = new PriceHistoryChart();
    $chart->filter = 'all';

    expect($chart->computeData()['datasets'])->toBe([]);

    $method = new ReflectionMethod(PriceHistoryChart::class, 'historyDays');

    expect($method->invoke($chart))->toBe(90);
});

it('renders an empty history on a long range without failing', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $this->actingAs($user);

    // A dataset shell with no points, not a crash and not a fabricated line.
    $data = chartFor($product, 'all')->computeData();

    expect($data['labels'])->toBe([])
        ->and($data['datasets'][0]['data'])->toBe([]);
});
