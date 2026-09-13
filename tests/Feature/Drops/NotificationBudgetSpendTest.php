<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Actions\Drops\DetectTargetPrice;
use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asking the budget spends a slot — `NotificationBudget::allows()` calls
 * `RateLimiter::hit()`. So the question may only be asked once the send is
 * certain. These tests pin that: a slot is spent when, and only when, the
 * user is actually notified.
 */
beforeEach(function (): void {
    Notification::fake();

    // A positive ceiling on both plans. `allows()` returns true without
    // hitting the limiter when the limit is zero or less, which would make
    // every assertion below pass with or without the fix.
    config()->set('plans.pro.notifications_hourly_limit', 5);
    config()->set('plans.free.notifications_hourly_limit', 5);
});

/**
 * A Pro product whose best value is EUR 5.38/kg against a EUR 5.50/kg target,
 * so the unit-price alert is due.
 */
function budgetUnitPriceProduct(): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => '5.50',
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1',
        'currency' => 'EUR',
        'current_price' => '1.99',
        'pack_quantity' => '370.00',
        'pack_unit' => 'g',
    ]);

    return $product->refresh();
}

/**
 * A free-plan product whose cheapest price of 17.05 is under an 18.00 target,
 * so the pack-price alert is due.
 */
function budgetTargetPriceProduct(): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'target_price' => '18.00',
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_price' => '17.05',
        'current_in_stock' => true,
    ]);

    $product->recomputeCheapestShop();

    return $product->refresh();
}

/**
 * A product whose cheapest price has fallen from a stable reference of 100 to
 * 85, far enough past both thresholds to fire.
 *
 * @return array{0: Product, 1: int}
 */
function budgetDroppingProduct(User $user): array
{
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/' . fake()->unique()->slug(),
        'currency' => 'EUR',
        'current_price' => '85.00',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '85.00'])->save();

    foreach (range(1, 8) as $i) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'cheapest_price' => '100.00',
            'started_at' => now()->subDays(20 - $i),
            'ended_at' => now()->subDays(19 - $i),
        ]);
    }

    $check = PriceCheck::factory()->for($shop)->create(['price' => '85.00']);

    return [$product, (int) $check->id];
}

test('a unit-price send that lands spends one slot', function (): void {
    $product = budgetUnitPriceProduct();
    $user = $product->user()->sole();

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(1);
});

test('a unit-price claim lost to another worker spends no slot', function (): void {
    $product = budgetUnitPriceProduct();
    $user = $product->user()->sole();

    // The other worker got there first: the stored latch already holds this
    // value, while this worker's in-memory copy still says null. The
    // conditional update therefore matches zero rows.
    Product::query()->whereKey($product->getKey())->update(['unit_price_notified' => '5.38']);

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(0);
});

test('a unit-price transaction that rolls back spends no slot', function (): void {
    $product = budgetUnitPriceProduct();
    $user = $product->user()->sole();

    // `CheckShopPrice::persist()` runs this action inside its own
    // transaction, with `DetectTargetPrice` after it. A throw there rolls the
    // claim back, but the cache-backed limiter does not roll back with it.
    try {
        DB::transaction(function () use ($product): void {
            app(DetectUnitPriceTarget::class)($product);

            throw new RuntimeException('the caller failed after the claim landed');
        });
    } catch (RuntimeException) {
        // Expected — the rollback is the point of the test.
    }

    expect($product->refresh()->unit_price_notified)->toBeNull()
        ->and(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(0);

    Notification::assertNothingSent();
});

test('a denied unit-price budget keeps the claim and drops that alert', function (): void {
    config()->set('plans.pro.notifications_hourly_limit', 1);

    $product = budgetUnitPriceProduct();
    $user = $product->user()->sole();

    RateLimiter::hit(NotificationBudget::key($user), 3600);

    app(DetectUnitPriceTarget::class)($product);

    // The claim landed before the ceiling was asked, so the latch stays armed
    // and this alert is dropped rather than retried on the next check. That
    // is what `DetectDrop` has always done.
    Notification::assertNothingSent();
    expect($product->refresh()->unit_price_notified)->toBe('5.38');
});

test('a target-price claim lost to another worker spends no slot', function (): void {
    $product = budgetTargetPriceProduct();
    $user = $product->user()->sole();

    Product::query()->whereKey($product->getKey())->update(['target_price_notified' => '17.05']);

    app(DetectTargetPrice::class)($product);

    Notification::assertNothingSent();
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(0);
});

test('a target-price transaction that rolls back spends no slot', function (): void {
    $product = budgetTargetPriceProduct();
    $user = $product->user()->sole();

    try {
        DB::transaction(function () use ($product): void {
            app(DetectTargetPrice::class)($product);

            throw new RuntimeException('the caller failed after the claim landed');
        });
    } catch (RuntimeException) {
        // Expected — the rollback is the point of the test.
    }

    expect($product->refresh()->target_price_notified)->toBeNull()
        ->and(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(0);

    Notification::assertNothingSent();
});

test('a suppressed unit-price alert says so in the log', function (): void {
    config()->set('plans.pro.notifications_hourly_limit', 1);

    $product = budgetUnitPriceProduct();
    $user = $product->user()->sole();

    RateLimiter::hit(NotificationBudget::key($user), 3600);

    Log::spy();

    app(DetectUnitPriceTarget::class)($product);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Notification suppressed by hourly rate limit'
            && $context['alert'] === 'unit_price_target'
            && $context['user_id'] === $user->id
            && $context['product_id'] === $product->id);

    Notification::assertNothingSent();
});

test('a suppressed target-price alert says so in the log', function (): void {
    config()->set('plans.free.notifications_hourly_limit', 1);

    $product = budgetTargetPriceProduct();
    $user = $product->user()->sole();

    RateLimiter::hit(NotificationBudget::key($user), 3600);

    Log::spy();

    app(DetectTargetPrice::class)($product);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Notification suppressed by hourly rate limit'
            && $context['alert'] === 'target_price'
            && $context['user_id'] === $user->id
            && $context['product_id'] === $product->id);

    Notification::assertNothingSent();
});

test('a drop transaction that rolls back spends no slot', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    [$product, $checkId] = budgetDroppingProduct($user);

    // The limiter is cache-backed, so it does not roll back with the
    // database. A caller that fails after the detector ran must not leave a
    // slot spent on an event row that no longer exists.
    try {
        DB::transaction(function () use ($product, $checkId): void {
            app(DetectDrop::class)($product, $checkId);

            throw new RuntimeException('the caller failed after the event row was written');
        });
    } catch (RuntimeException) {
        // Expected — the rollback is the point of the test.
    }

    expect(PriceDropEvent::query()->count())->toBe(0)
        ->and(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(0);

    Notification::assertNothingSent();
});

test('a drop transaction that commits spends one slot', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    [$product, $checkId] = budgetDroppingProduct($user);

    DB::transaction(function () use ($product, $checkId): void {
        app(DetectDrop::class)($product, $checkId);
    });

    expect(PriceDropEvent::query()->count())->toBe(1)
        ->and(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(1);

    // The send is deferred to the outermost commit, which is the shape
    // `CheckShopPrice::persist()` produces. It must still arrive.
    Notification::assertSentTo($user, PriceDropNotification::class);
});
