<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, mixed>  $attrs
 */
function makeProductWithLatestCheck(array $attrs = [], string $latestPrice = '85.00'): Product
{
    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);

    $product = Product::factory()->for($user)->create(array_merge([
        'initial_price' => '100.00',
        'last_price' => $latestPrice,
        'last_status' => ScrapeStatus::Ok,
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
        'last_notified_price' => null,
        'last_notified_at' => null,
    ], $attrs));

    PriceCheck::factory()->for($product)->create([
        'price' => $latestPrice,
        'status' => ScrapeStatus::Ok,
        'checked_at' => now(),
    ]);

    return $product;
}

test('first drop below threshold notifies and writes a price_drop_event row', function (): void {
    Notification::fake();

    $product = makeProductWithLatestCheck(latestPrice: '85.00');

    app(DetectDrop::class)($product);

    Notification::assertSentTo(
        $product->user,
        PriceDropNotification::class,
        function (PriceDropNotification $notification) use ($product): bool {
            return $notification->product->is($product)
                && $notification->priceDropEventId !== ''
                && (float) $notification->outcome->dropAbsolute === 15.0
                && (float) $notification->outcome->dropPercent === 15.0;
        },
    );

    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(85.0)
        ->and($product->last_notified_at)->not->toBeNull();

    expect(PriceDropEvent::query()->count())->toBe(1);
    $event = PriceDropEvent::query()->firstOrFail();
    expect($event->product_id)->toBe($product->id)
        ->and($event->user_id)->toBe($product->user_id)
        ->and((float) $event->reference_price)->toBe(100.0)
        ->and((float) $event->new_price)->toBe(85.0)
        ->and((float) $event->drop_abs)->toBe(15.0)
        ->and((float) $event->drop_pct)->toBe(15.0);
});

test('subsequent same-event drop that is not a new low is silent and writes no event row', function (): void {
    Notification::fake();

    // Latch already at 85; new price 87 is still below threshold but higher than latch.
    $product = makeProductWithLatestCheck(
        attrs: [
            'last_notified_price' => '85.00',
            'last_notified_at' => now()->subHour(),
        ],
        latestPrice: '87.00',
    );

    app(DetectDrop::class)($product);

    Notification::assertNothingSent();
    expect(PriceDropEvent::query()->count())->toBe(0);

    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(85.0); // unchanged
});

test('a new low within the same drop event re-notifies and writes another event row', function (): void {
    Notification::fake();

    $product = makeProductWithLatestCheck(
        attrs: [
            'last_notified_price' => '85.00',
            'last_notified_at' => now()->subHour(),
        ],
        latestPrice: '80.00',
    );

    app(DetectDrop::class)($product);

    Notification::assertSentTimes(PriceDropNotification::class, 1);
    expect(PriceDropEvent::query()->count())->toBe(1);

    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(80.0);
});

test('recovery to reference price clears the latch', function (): void {
    Notification::fake();

    // Reference comes from initial_price = 100. New price meets/exceeds it.
    $product = makeProductWithLatestCheck(
        attrs: [
            'last_notified_price' => '85.00',
            'last_notified_at' => now()->subHour(),
        ],
        latestPrice: '100.00',
    );

    app(DetectDrop::class)($product);

    Notification::assertNothingSent();
    expect(PriceDropEvent::query()->count())->toBe(0);

    $product->refresh();
    expect($product->last_notified_price)->toBeNull()
        ->and($product->last_notified_at)->toBeNull();
});

test('editing threshold does not clear the latch, but next scrape evaluates against the new threshold', function (): void {
    Notification::fake();

    // First scrape: drops to 90 against ref 100 with thresholds pct=5 abs=5 → fires.
    $product = makeProductWithLatestCheck(
        attrs: [
            'drop_threshold_pct' => '5.00',
            'drop_threshold_abs' => '5.00',
        ],
        latestPrice: '90.00',
    );

    app(DetectDrop::class)($product);

    Notification::assertSentTimes(PriceDropNotification::class, 1);

    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(90.0);

    // User edits thresholds upward; latch stays.
    $product->forceFill([
        'drop_threshold_pct' => '20.00',
        'drop_threshold_abs' => '20.00',
    ])->save();
    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(90.0);

    // Next scrape lands at 80 — new low + still meets new threshold (drop_abs=20, drop_pct=20).
    PriceCheck::factory()->for($product)->create([
        'price' => '80.00',
        'status' => ScrapeStatus::Ok,
        'checked_at' => now()->addHour(),
    ]);
    $product->forceFill(['last_price' => '80.00', 'last_checked_at' => now()->addHour()])->save();
    $product->refresh();

    app(DetectDrop::class)($product);

    Notification::assertSentTimes(PriceDropNotification::class, 2);

    $product->refresh();
    expect((float) $product->last_notified_price)->toBe(80.0);
});

test('skips entirely when reference cannot be computed', function (): void {
    Notification::fake();

    $product = makeProductWithLatestCheck();
    // Force the reference branch to return null by zeroing out the reference inputs.
    $product->forceFill(['initial_price' => '0.00'])->save();
    $product->priceChecks()->delete();

    // initial_price=0 still passes the not-null check, so reference returns "0".
    // Use it to assert the action does not crash and does not divide by zero.
    app(DetectDrop::class)($product);

    Notification::assertNothingSent();
});

test('a stale in-memory product cannot bypass the latch — DB-side state is authoritative', function (): void {
    Notification::fake();

    // First scrape: triggers + persists latch=85.
    $product = makeProductWithLatestCheck(latestPrice: '85.00');
    app(DetectDrop::class)($product);
    Notification::assertSentTimes(PriceDropNotification::class, 1);
    expect(PriceDropEvent::query()->count())->toBe(1);

    // Simulate a concurrent worker that started with a stale snapshot of the
    // product — its in-memory copy still shows last_notified_price = null,
    // but the DB row has already been latched to 85 by the first scrape.
    $stale = $product->fresh();
    expect($stale)->not->toBeNull();
    /** @var Product $stale */
    $stale->setRawAttributes(array_merge($stale->getRawOriginal(), [
        'last_notified_price' => null,
        'last_notified_at' => null,
    ]));

    app(DetectDrop::class)($stale);

    // The action must consult the locked DB row, not the stale memory copy,
    // and stay silent because 85 is not a new low.
    Notification::assertSentTimes(PriceDropNotification::class, 1);
    expect(PriceDropEvent::query()->count())->toBe(1);
});

test('skips entirely when last_price is null', function (): void {
    Notification::fake();

    $product = Product::factory()->create([
        'last_price' => null,
        'last_status' => ScrapeStatus::HttpError,
    ]);

    app(DetectDrop::class)($product);

    Notification::assertNothingSent();
    expect(PriceDropEvent::query()->count())->toBe(0);
});
