<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * @param  array<string, mixed>  $attrs
 */
function makeDroppingProductForUser(User $user, array $attrs = []): Product
{
    $product = Product::factory()->for($user)->create(array_merge([
        'initial_price' => '100.00',
        'last_price' => '85.00',
        'last_status' => ScrapeStatus::Ok,
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
        'last_notified_price' => null,
        'last_notified_at' => null,
    ], $attrs));

    PriceCheck::factory()->for($product)->create([
        'price' => '85.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => now(),
    ]);

    return $product;
}

test('the per-user hourly notification limit suppresses excess notifications but still writes the event row', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 3);

    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);

    // Three distinct products dropping in the same hour — each generates a fresh
    // price_drop_event because the latch is per-product.
    for ($n = 0; $n < 4; $n++) {
        $product = makeDroppingProductForUser($user);
        app(DetectDrop::class)($product);
    }

    expect(PriceDropEvent::query()->where('user_id', $user->id)->count())->toBe(4);

    Notification::assertSentToTimes($user, PriceDropNotification::class, 3);
});

test('limit set to zero disables rate-limiting entirely', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 0);

    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => false,
        'notify_via_push' => false,
    ]);

    for ($n = 0; $n < 5; $n++) {
        app(DetectDrop::class)(makeDroppingProductForUser($user));
    }

    Notification::assertSentToTimes($user, PriceDropNotification::class, 5);
});

test('separate users do not share the rate-limit bucket', function (): void {
    Notification::fake();
    config()->set('dipcatch.notifications.user_hourly_limit', 1);

    $alice = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => false,
        'notify_via_push' => false,
    ]);
    $bob = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => false,
        'notify_via_push' => false,
    ]);

    app(DetectDrop::class)(makeDroppingProductForUser($alice));
    app(DetectDrop::class)(makeDroppingProductForUser($alice));
    app(DetectDrop::class)(makeDroppingProductForUser($bob));

    Notification::assertSentToTimes($alice, PriceDropNotification::class, 1);
    Notification::assertSentToTimes($bob, PriceDropNotification::class, 1);
});
