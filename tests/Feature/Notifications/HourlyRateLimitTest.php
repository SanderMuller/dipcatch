<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * Build a product whose cheapest_price is below threshold against a stable
 * historical reference of 100. Returns the freshly-created triggering
 * price_check id.
 *
 * @return array{0: Product, 1: int}
 */
function makeDroppingProductForUser(User $user): array
{
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ]);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/' . fake()->unique()->slug(),
        'current_price' => '85.00',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '85.00'])->save();

    // Seed 8 stable 100.00 segments in the 30-day window so Reference picks
    // median (or falls back to initial=100). Either way the new cheapest is
    // well below threshold.
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

test('the per-user hourly notification limit suppresses excess notifications but still writes the event row', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 3);

    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);

    for ($n = 0; $n < 4; $n++) {
        [$product, $checkId] = makeDroppingProductForUser($user);
        app(DetectDrop::class)($product, $checkId);
    }

    expect(PriceDropEvent::query()->where('user_id', $user->id)->count())->toBe(4);
    Notification::assertSentToTimes($user, PriceDropNotification::class, 3);
});

test('limit set to zero disables rate-limiting entirely', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 0);

    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);

    for ($n = 0; $n < 5; $n++) {
        [$product, $checkId] = makeDroppingProductForUser($user);
        app(DetectDrop::class)($product, $checkId);
    }

    Notification::assertSentToTimes($user, PriceDropNotification::class, 5);
});

test('separate users do not share the rate-limit bucket', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 1);

    $alice = User::factory()->create(['notify_via_email' => false, 'notify_via_filament' => true]);
    $bob = User::factory()->create(['notify_via_email' => false, 'notify_via_filament' => true]);

    [$p, $c] = makeDroppingProductForUser($alice);
    app(DetectDrop::class)($p, $c);
    [$p2, $c2] = makeDroppingProductForUser($alice);
    app(DetectDrop::class)($p2, $c2);
    [$pb, $cb] = makeDroppingProductForUser($bob);
    app(DetectDrop::class)($pb, $cb);

    Notification::assertSentToTimes($alice, PriceDropNotification::class, 1);
    Notification::assertSentToTimes($bob, PriceDropNotification::class, 1);
});
