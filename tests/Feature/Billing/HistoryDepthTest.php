<?php declare(strict_types=1);

use App\Billing\HistoryWindow;
use App\Charts\PriceHistorySeries;
use App\Livewire\Products\ProductShow;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;

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

function chartFor(Product $product, string $filter): PriceHistorySeries
{
    return new PriceHistorySeries($product, $filter);
}

/**
 * The price of the oldest point the chart plotted, or null when the long
 * segment is outside the window.
 */
function plotsTheOldSegment(PriceHistorySeries $chart): bool
{
    $data = $chart->data();
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

it('plots a segment older than a year only under all time', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = productWithOldHistory($user);
    $shop = Shop::query()->where('product_id', $product->id)->firstOrFail();

    // Older than every named range, so it separates "All time" from 365 days
    // — the pair the free plan cannot reach at all.
    ProductCheapestHistory::create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '42.42',
        'started_at' => CarbonImmutable::now()->subDays(500),
        'ended_at' => CarbonImmutable::now()->subDays(450),
    ]);
    $this->actingAs($user);

    $plotsTheAncientSegment = function (string $filter) use ($product): bool {
        $prices = chartFor($product, $filter)->data()['datasets'][0]['data'] ?? [];

        foreach ((array) $prices as $price) {
            if (is_numeric($price) && abs((float) $price - 42.42) < 0.001) {
                return true;
            }
        }

        return false;
    };

    expect($plotsTheAncientSegment('all'))->toBeTrue()
        ->and($plotsTheAncientSegment('365'))->toBeFalse();
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

    $markers = chartFor($product, 'all')->data()['datasets'][2]['data'] ?? [];

    expect(array_filter((array) $markers, static fn (mixed $m): bool => $m !== null))->toBe([]);
});

it('tells a free account why the long ranges are missing', function (): void {
    configureStripe();

    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Your plan shows the last 90 days')
        ->assertSee('Compare plans');
});

it('does not advertise pro while the shop is shut', function (): void {
    // Nothing is on sale, so the reason stays and the link goes.
    $user = User::factory()->create();
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Your plan shows the last 90 days')
        ->assertDontSee('Compare plans');
});

it('says nothing about plans to an account with no ceiling', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = productWithOldHistory($user);
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertDontSee('Your plan shows the last');
});

it('keeps the public shared page at 90 days for a pro owner', function (): void {
    // The public route is throttled with a Redis limiter that outlives the
    // test run, so a suite that reached it earlier leaves the bucket full and
    // this case reads 429 instead of the page. PublicProductControllerTest
    // clears the same limiter for the same reason.
    clearRedisRateLimiter('public-product');

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
    $product = Product::factory()->create(['currency' => 'EUR']);

    // A gate that opens when it cannot tell who is asking is not a gate.
    // The widget could be constructed with no record at all, which is how that
    // hole existed; PriceHistorySeries requires a product, so the only
    // remaining unknown-owner case is a product whose user relation is empty.
    $series = new PriceHistorySeries($product, 'all');

    $method = new ReflectionMethod(PriceHistorySeries::class, 'historyDays');

    expect($method->invoke($series))->toBe(90);
});

it('renders an empty history on a long range without failing', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $this->actingAs($user);

    // A dataset shell with no points, not a crash and not a fabricated line.
    $data = chartFor($product, 'all')->data();

    expect($data['labels'])->toBe([])
        ->and($data['datasets'][0]['data'])->toBe([]);
});
