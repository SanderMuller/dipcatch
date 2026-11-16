<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `CheckShopPrice::persist()` wraps the price_check insert, the recompute and
 * the target detector in one transaction, and the recompute opens another for
 * the row lock. Every alert asks the hourly budget and sends from a
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
 * Two products of one owner, each with one 370 g shop at 25.00 whose next
 * check reads 17.05. One check fires one alert at most: a product with a
 * live target alerts on its target and on no drop. So:
 *
 * - `drop` has no target. 25.00 → 17.05 is 31.8% and €7.95 off a stable
 *   25.00 window, past the default 10% / €7 and under the 40% confirmation.
 * - `target` has a 50.00/kg target. 17.05 for 370 g is 46.08/kg.
 *
 * Each shop has its own host, so the second check never waits on the first
 * one's per-host limit.
 *
 * @return array{drop: Shop, target: Shop}
 */
function jobTransactionShops(): array
{
    $user = User::factory()->create(['notify_via_filament' => true]);

    return [
        'drop' => jobTransactionShop($user, 'shop-a.test', []),
        'target' => jobTransactionShop($user, 'shop-b.test', [
            'unit_price_target' => '50.00',
            'unit_price_target_unit' => 'g',
        ]),
    ];
}

/**
 * @param  array<model-property<Product>, mixed>  $productAttributes
 */
function jobTransactionShop(User $user, string $host, array $productAttributes): Shop
{
    Http::fake([
        "https://{$host}/robots.txt" => Http::response('', 404),
        "https://{$host}/p/1" => Http::response(withJsonLd(json_encode([
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

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        ...$productAttributes,
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => "https://{$host}/p/1",
        'currency' => 'EUR',
        'current_price' => '25.00',
        'current_in_stock' => true,
        'pack_quantity' => '370.00',
        'pack_unit' => 'g',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '25.00'])->save();

    // The segment carries its own size, which is what the drop reference reads:
    // a product compared per kilo needs unit-bearing history, and a segment
    // written before sizes existed stays explicitly unmeasured.
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '25.00',
        'best_value_shop_id' => $shop->id,
        'best_value_price' => '25.00',
        'pack_quantity' => '370.00',
        'pack_unit' => 'g',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    return $shop;
}

/**
 * Run both checks inside one transaction, as any caller that holds one open
 * would. Both jobs' alert callbacks then wait for the one outer commit and
 * run there in the order they were staged: the drop's first, the target's
 * second. Run apart, each job's own commit runs its own callbacks, and a
 * throw in the first job has nothing in the second to take down.
 *
 * @param  array{drop: Shop, target: Shop}  $shops
 */
function checkBothInOneTransaction(array $shops): void
{
    DB::transaction(function () use ($shops): void {
        dispatch_sync(new CheckShopPrice($shops['drop']));

        // Proof the first job's alert waits for the outer commit, and so
        // shares one run of the callbacks with the second job's alert.
        Notification::assertNothingSent();

        dispatch_sync(new CheckShopPrice($shops['target']));
    });
}

/**
 * Bind a stand-in for `NotificationBudget` that throws on its first call. That
 * first call is always the `drop` product's `DetectDrop`, because its check
 * runs first.
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

test('both alerts arrive through the job transaction', function (): void {
    $shops = jobTransactionShops();
    $user = $shops['drop']->product->user()->sole();

    checkBothInOneTransaction($shops);

    expect((string) $shops['drop']->product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $shops['drop']->product_id)->count())->toBe(1);

    Notification::assertSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);

    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(2);
});

test('a failing drop alert does not take the target alert with it', function (): void {
    $shops = jobTransactionShops();
    $user = $shops['drop']->product->user()->sole();
    bindBudgetThatFailsOnce();

    checkBothInOneTransaction($shops);

    // The drop alert is lost — that is the accepted cost of sending after the
    // commit. The target alert has no business dying with it: its claim
    // committed and its latch is armed. It runs after the throw, so it proves
    // the callbacks resume rather than stop.
    Notification::assertNotSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});

test('a failing alert does not fail the job', function (): void {
    $shop = jobTransactionShops()['drop'];
    bindBudgetThatFailsOnce();

    // `handle()` calls `persist()` outside every one of its try blocks and
    // `CheckShopPrice` sets `maxExceptions = 1`, so before this change an
    // exception escaping a commit callback failed the check outright.
    dispatch_sync(new CheckShopPrice($shop));
})->throwsNoExceptions();

test('a failing alert is reported and logged, not swallowed', function (): void {
    Exceptions::fake();
    Log::spy();

    $shop = jobTransactionShops()['drop'];
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
    $shops = jobTransactionShops();
    bindBudgetThatFailsOnce();

    checkBothInOneTransaction($shops);

    $dropped = $shops['drop']->product->refresh();
    $targeted = $shops['target']->product->refresh();

    // The catch must not change the transaction semantics: the price, the
    // event row and every latch are committed before any callback runs.
    expect((string) $dropped->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $dropped->id)->count())->toBe(1)
        // The drop latch holds money in the basis the drop was measured in —
        // per kilo here, with `last_notified_unit` saying so.
        ->and((string) $dropped->last_notified_price)->toBe('46.08')
        ->and($dropped->last_notified_unit)->toBe('g')
        ->and((string) $targeted->cheapest_price)->toBe('17.05')
        ->and((string) $targeted->unit_price_notified)->toBe('46.0811');
});
