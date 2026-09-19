<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * A drop at or past `drops.confirm_above_pct` below the reference is what one
 * mis-extraction looks like — a unit price, a "from" price, another variant.
 * It notifies only when the shop's previous eligible reading agreed.
 */
beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    config()->set('dipcatch.drops.confirm_above_pct', 40);
    config()->set('dipcatch.drops.confirm_delay_minutes', 10);
});

/**
 * A product sitting at 100.00 on one shop, with an open history segment so a
 * reference exists. The thresholds fire on any drop past 5%, so the
 * confirmation ceiling is the only thing separating a small drop from a large
 * one.
 */
function confirmationProduct(string $host = 'shop.example.com'): Shop
{
    $user = User::factory()->create(['notify_via_filament' => true]);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '1.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://' . $host . '/p/' . fake()->unique()->slug(),
        'host' => $host,
        'currency' => 'EUR',
        'current_price' => '100.00',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '100.00'])->save();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    return $shop->refresh();
}

/** Store a reading on the shop and run the recompute the way the job does.
 * @param array<string, mixed> $attributes */
function reading(Shop $shop, ?string $price, array $attributes = []): PriceCheck
{
    $check = PriceCheck::factory()->for($shop)->create(['price' => $price] + $attributes);

    if (($attributes['status'] ?? ScrapeStatus::Ok) === ScrapeStatus::Ok && $price !== null) {
        $shop->update(['current_price' => $price]);
    }

    $shop->product->refresh()->recomputeCheapestShop((int) $check->id);

    return $check;
}

test('a first large reading notifies nothing and asks for a second opinion', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(0);
    Notification::assertNothingSent();

    Queue::assertPushed(CheckShopPrice::class, function (CheckShopPrice $job) use ($shop): bool {
        $delay = $job->delay;

        return $job->shop->id === $shop->id
            && $job->confirmation
            && $delay instanceof DateTimeInterface
            && round(now()->diffInMinutes($delay, true)) === 10.0;
    });
});

test('a confirmation the closure cannot complete is reported, not thrown', function (): void {
    Exceptions::fake();
    Log::spy();

    $shop = confirmationProduct();

    // `Config::integer()` refuses a non-int, so this is the closure's own
    // failure rather than a mocked queue: the shape a bad deploy-time value
    // would take. Plan 010's rule is that no `afterCommit` closure in this
    // directory may let a throw escape — this one is staged before the
    // unit-price and target-price callbacks for the same check, so a rethrow
    // costs those alerts too, and `maxExceptions = 1` fails the job outright
    // rather than retrying it.
    //
    // Nothing is asserted about the queue here. `dispatch()` builds a
    // PendingDispatch before the delay argument is evaluated, so the job is
    // still pushed by its destructor as the throw unwinds — framework
    // behaviour this test has no business pinning.
    config()->set('dipcatch.drops.confirm_delay_minutes', 'ten');

    reading($shop, '40.00');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Confirmation check failed to dispatch'
            && $context['alert'] === 'price_drop'
            && $context['shop_id'] === $shop->id
            && $context['product_id'] === $shop->product_id);

    // `report()` is what keeps this catch from being a silent failure.
    Exceptions::assertReported(
        fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'confirm_delay_minutes'),
    );
});

test('a second reading at the same price confirms the drop and notifies once', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');
    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(1);

    // The alert names the confirming reading, not the first one.
    $event = PriceDropEvent::query()->sole();
    $latest = PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    expect((int) $event->price_check_id)->toBe((int) $latest->id);
});

test('a third qualifying reading does not notify again', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');
    reading($shop, '40.00');
    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(1);
});

test('a recovered second reading confirms nothing', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');
    reading($shop, '99.00');

    expect(PriceDropEvent::count())->toBe(0);
    Notification::assertNothingSent();
});

test('a failed second check neither confirms nor resets', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');

    // A failure leaves `current_price` at 40.00 and still recomputes, so
    // without the eligibility guard the cached drop would notify here.
    reading($shop, null, ['status' => ScrapeStatus::HttpError, 'in_stock' => null]);

    expect(PriceDropEvent::count())->toBe(0);

    // The next real reading still confirms against the first one.
    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(1);
});

test('an out-of-stock previous reading cannot confirm', function (): void {
    $shop = confirmationProduct();

    // Out of stock: excluded from cheapest selection, so it never had a drop
    // of its own and must not stand in as the second opinion.
    PriceCheck::factory()->for($shop)->create(['price' => '40.00', 'in_stock' => false]);

    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(0);
    Queue::assertPushed(CheckShopPrice::class);
});

test('a small drop still notifies on one reading', function (): void {
    $shop = confirmationProduct();

    reading($shop, '85.00');

    expect(PriceDropEvent::count())->toBe(1);
    Queue::assertNotPushed(CheckShopPrice::class);
});

test('a dataset shop notifies on one reading and asks for nothing', function (string $host): void {
    $shop = confirmationProduct($host);

    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(1);
    Queue::assertNotPushed(CheckShopPrice::class);
    // boodschaapje.nl is Checkjebon-only; ah.nl is matched by both sources.
})->with(['ah.nl', 'boodschaapje.nl']);

test('a check on another shop confirms nothing', function (): void {
    $shop = confirmationProduct();
    $product = $shop->product()->sole();

    $rival = Shop::factory()->for($product)->create([
        'url' => 'https://rival.example.com/p/' . fake()->unique()->slug(),
        'host' => 'rival.example.com',
        'currency' => 'EUR',
        'current_price' => '120.00',
    ]);

    reading($shop, '40.00');
    reading($rival, '120.00');

    expect(PriceDropEvent::count())->toBe(0);

    // The dropping shop's own next reading is what confirms it.
    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(1);
});

test('a lower confirming reading still confirms the drop', function (): void {
    $shop = confirmationProduct();

    reading($shop, '40.00');
    reading($shop, '35.00');

    expect(PriceDropEvent::count())->toBe(1);
});

test('a product with no reference notifies nothing and asks for nothing', function (): void {
    $shop = confirmationProduct();

    // No history segment, so `Reference::compute()` returns null and the hook
    // is never reached.
    ProductCheapestHistory::query()->delete();

    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(0);
    Queue::assertNotPushed(CheckShopPrice::class);
});

test('a merely discounted predecessor does not confirm a large drop', function (): void {
    $shop = confirmationProduct();

    // 15% below the reference: a drop the user is notified about, but not one
    // that can vouch for the 60% reading behind it.
    reading($shop, '85.00');
    expect(PriceDropEvent::count())->toBe(1);

    reading($shop, '40.00');

    // Still one event — the 85.00 one. The 40.00 reading waits for its own
    // second opinion.
    expect(PriceDropEvent::count())->toBe(1);
    Queue::assertPushed(CheckShopPrice::class);

    reading($shop, '40.00');

    expect(PriceDropEvent::count())->toBe(2);
});
