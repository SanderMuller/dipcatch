<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `CheckShopPrice::persist()` wraps the price_check insert, the recompute and
 * both target detectors in one transaction, and the recompute opens another
 * for the row lock. Every alert now asks the hourly budget and sends from a
 * `DB::afterCommit` callback three levels down. The hand-rolled transactions
 * in NotificationBudgetSpendTest only go two deep, so this drives the real
 * job.
 */
beforeEach(function (): void {
    Notification::fake();

    // A positive ceiling, or `allows()` returns true without touching the
    // limiter and the slot assertions below prove nothing.
    config()->set('plans.free.notifications_hourly_limit', 5);
    config()->set('plans.pro.notifications_hourly_limit', 5);
});

/**
 * A product at 25.00 whose next check reads 17.05. That is a drop against a
 * stable 25.00 window, under the 18.00 pack-price target, and — at 370 g —
 * under the 50.00/kg unit-price target. One check, all three alerts.
 *
 * All three matter: `DetectDrop` stages its callback deepest and runs first,
 * so a cascade test that omitted the unit-price alert would never prove that
 * the `foreach` resumes after the throw, only that it reaches the last entry.
 *
 * Returns the shop the check runs against; its product and owner hang off
 * it, so no test has to carry variables it does not assert on.
 */
function jobTransactionShop(): Shop
{
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Olive oil',
            'offers' => [
                '@type' => 'Offer',
                'price' => '17.05',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create(['notify_via_filament' => true]);

    // The unit-price target is a Pro feature, so without this the middle
    // detector returns before it stages a callback.
    subscribeUser($user);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'target_price' => '18.00',
        'unit_price_target' => '50.00',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_price' => '25.00',
        'current_in_stock' => true,
        'pack_quantity' => '370.00',
        'pack_unit' => 'g',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '25.00'])->save();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '25.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    return $shop;
}

/**
 * Bind a stand-in for `NotificationBudget` that throws on its first call. That
 * first call is always `DetectDrop`'s, because its callback is staged deepest
 * and therefore runs first.
 *
 * `NotificationBudget` is `final`, so it can be neither subclassed nor
 * doubled. It does not need to be: every call site resolves it untyped as
 * `app(NotificationBudget::class)->allows($user)`, so any object with that
 * method serves.
 */
function bindBudgetThatFailsOnce(): void
{
    app()->instance(NotificationBudget::class, new class {
        private bool $thrown = false;

        public function allows(User $user): bool
        {
            if (! $this->thrown) {
                $this->thrown = true;

                throw new RuntimeException('the rate limiter is unreachable');
            }

            return true;
        }
    });
}

test('all three alerts arrive through the job transaction', function (): void {
    $shop = jobTransactionShop();
    $product = $shop->product;
    $user = $product->user()->sole();

    dispatch_sync(new CheckShopPrice($shop));

    expect((string) $product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);

    Notification::assertSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, TargetPriceNotification::class);
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);

    // Three alerts sent, three slots spent — never more.
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(3);
});

test('a failing drop alert does not take the other two with it', function (): void {
    $shop = jobTransactionShop();
    $user = $shop->product->user()->sole();
    bindBudgetThatFailsOnce();

    dispatch_sync(new CheckShopPrice($shop));

    // The drop alert is lost — that is the accepted cost of sending after the
    // commit. The other two have no business dying with it: their claims
    // committed and their latches are armed. The unit-price alert is the one
    // that proves the loop resumes rather than merely reaching its last entry.
    Notification::assertNotSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, TargetPriceNotification::class);
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});

test('a failing alert does not fail the job', function (): void {
    $shop = jobTransactionShop();
    bindBudgetThatFailsOnce();

    // `handle()` calls `persist()` outside every one of its try blocks and
    // `CheckShopPrice` sets `maxExceptions = 1`, so before this change an
    // exception escaping a commit callback failed the check outright.
    dispatch_sync(new CheckShopPrice($shop));
})->throwsNoExceptions();

test('a failing alert is reported and logged, not swallowed', function (): void {
    Exceptions::fake();
    Log::spy();

    $shop = jobTransactionShop();
    $product = $shop->product;
    $user = $product->user()->sole();
    bindBudgetThatFailsOnce();

    dispatch_sync(new CheckShopPrice($shop));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Alert failed to send'
            && $context['alert'] === 'price_drop'
            && $context['user_id'] === $user->id
            && $context['product_id'] === $product->id
            && $context['price_drop_event_id'] !== null);

    // `report()` is the only thing keeping this catch from being a silent
    // failure. Without it the alert vanishes with no trace anywhere. Pinned on
    // the message, so only the stub's own throw can satisfy it.
    Exceptions::assertReported(
        fn (RuntimeException $e): bool => $e->getMessage() === 'the rate limiter is unreachable',
    );
});

test('a failing alert leaves the committed data alone', function (): void {
    $shop = jobTransactionShop();
    $product = $shop->product;
    bindBudgetThatFailsOnce();

    dispatch_sync(new CheckShopPrice($shop));

    // The catch must not change the transaction semantics: the price, the
    // event row and every latch are committed before any callback runs.
    expect((string) $product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and((string) $product->last_notified_price)->toBe('17.05')
        ->and((string) $product->target_price_notified)->toBe('17.05')
        ->and((string) $product->unit_price_notified)->toBe('46.08');
});
