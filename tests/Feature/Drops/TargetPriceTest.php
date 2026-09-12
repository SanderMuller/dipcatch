<?php declare(strict_types=1);

use App\Actions\Drops\DetectTargetPrice;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\SetThresholdTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\TargetPriceNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Tell me when this is under 18 euro." A price to reach, not a fall, and
 * about the pack rather than the kilo — the shops that publish no pack size
 * can only answer this one.
 */
beforeEach(function (): void {
    Notification::fake();
});

function targetPriceProduct(?string $target, string $price = '17.05'): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'target_price' => $target,
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_price' => $price,
        'current_in_stock' => true,
    ]);

    $product->recomputeCheapestShop();

    return $product->refresh();
}

test('the alert fires when the cheapest price reaches the target', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);

    Notification::assertSentTo($product->user, TargetPriceNotification::class);

    expect((string) $product->refresh()->target_price_notified)->toBe('17.05');
});

test('the alert stays quiet above the target', function (): void {
    $product = targetPriceProduct('15.00');

    app(DetectTargetPrice::class)($product);

    Notification::assertNothingSent();
});

test('the same news is not sent twice, and a lower price is news again', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);
    app(DetectTargetPrice::class)($product->refresh());

    Notification::assertSentToTimes($product->user, TargetPriceNotification::class, 1);

    $product->shops()->first()?->update(['current_price' => '15.00']);
    $product->recomputeCheapestShop();

    app(DetectTargetPrice::class)($product->refresh());

    Notification::assertSentToTimes($product->user, TargetPriceNotification::class, 2);
});

test('a price back above the target clears the latch', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);

    $product->shops()->first()?->update(['current_price' => '21.00']);
    $product->recomputeCheapestShop();

    app(DetectTargetPrice::class)($product->refresh());

    expect($product->refresh()->target_price_notified)->toBeNull();
});

test('a product without a target says nothing', function (): void {
    $product = targetPriceProduct(null);

    app(DetectTargetPrice::class)($product);

    Notification::assertNothingSent();
});

test('set_threshold stores a target price on a free account, with no Pro caveat', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'target_price' => null]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'target_price' => 18])
        ->assertOk()
        ->assertDontSee('Pro feature');

    expect((float) $product->fresh()?->target_price)->toBe(18.0);
});

test('the notification budget still caps a target alert', function (): void {
    $product = targetPriceProduct('18.00');
    $user = $product->user;
    assert($user !== null);

    // Spend the hour's budget before the check runs.
    $limit = $user->entitlements()->notificationsHourlyLimit();
    foreach (range(1, $limit) as $ignored) {
        RateLimiter::hit(NotificationBudget::key($user), decaySeconds: 3600);
    }

    app(DetectTargetPrice::class)($product);

    Notification::assertNothingSent();
});
