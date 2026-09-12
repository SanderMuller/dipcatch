<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\Http;
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
    config()->set('plans.free.notifications_hourly_limit', 5);
});

/**
 * A product at 25.00 whose next check reads 17.05 — a drop against a stable
 * 25.00 window, and under the 18.00 pack-price target. One check, two alerts.
 *
 * @return array{0: Product, 1: Shop, 2: User}
 */
function jobTransactionProduct(): array
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

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'target_price' => '18.00',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_price' => '25.00',
        'current_in_stock' => true,
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '25.00'])->save();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '25.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    return [$product, $shop, $user];
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

test('a drop and a pack-price target both arrive through the job transaction', function (): void {
    [$product, $shop, $user] = jobTransactionProduct();

    dispatch_sync(new CheckShopPrice($shop));

    expect((string) $product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);

    Notification::assertSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, TargetPriceNotification::class);

    // Two alerts sent, two slots spent — never more.
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(2);
});

test('a failing drop alert does not take the pack-price alert with it', function (): void {
    [$product, $shop, $user] = jobTransactionProduct();
    bindBudgetThatFailsOnce();

    dispatch_sync(new CheckShopPrice($shop));

    // The drop alert is lost — that is the accepted cost of sending after the
    // commit. The target alert has no business dying with it: its own claim
    // committed and its own latch is armed.
    Notification::assertNotSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, TargetPriceNotification::class);
});

test('a failing alert does not fail the job', function (): void {
    [$product, $shop, $user] = jobTransactionProduct();
    bindBudgetThatFailsOnce();

    // `handle()` calls `persist()` outside every one of its try blocks, so an
    // exception escaping a commit callback fails the whole check.
    dispatch_sync(new CheckShopPrice($shop));
})->throwsNoExceptions();

test('a failing alert leaves the committed data alone', function (): void {
    [$product, $shop, $user] = jobTransactionProduct();
    bindBudgetThatFailsOnce();

    dispatch_sync(new CheckShopPrice($shop));

    // The catch must not change the transaction semantics: the price, the
    // event row and both latches are committed before any callback runs.
    expect((string) $product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and((string) $product->last_notified_price)->toBe('17.05')
        ->and((string) $product->target_price_notified)->toBe('17.05');
});
