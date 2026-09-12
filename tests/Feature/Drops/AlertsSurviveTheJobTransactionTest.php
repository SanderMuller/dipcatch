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

test('a drop and a pack-price target both arrive through the job transaction', function (): void {
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

    // A stable 25.00 window, so 17.05 is a drop against it as well as being
    // under the 18.00 target.
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '25.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect((string) $product->refresh()->cheapest_price)->toBe('17.05')
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);

    Notification::assertSentTo($user, PriceDropNotification::class);
    Notification::assertSentTo($user, TargetPriceNotification::class);

    // Two alerts sent, two slots spent — never more.
    expect(RateLimiter::attempts(NotificationBudget::key($user)))->toBe(2);
});
