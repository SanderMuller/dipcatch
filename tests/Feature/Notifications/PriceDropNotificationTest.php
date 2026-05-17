<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropOutcome;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

function buildOutcome(): DropOutcome
{
    return new DropOutcome(
        belowThreshold: true,
        referencePrice: '100.00',
        referenceKind: 'median_30d',
        dropAbsolute: '15.00',
        dropPercent: '15.00',
        thresholdAbs: '5.00',
        thresholdPct: '10.00',
    );
}

function buildProductAt85(User $user): Product
{
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => 'Acme Headphones',
        'image_url' => 'https://example.com/img.png',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '85.00',
    ]);

    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '85.00',
    ])->save();

    return $product;
}

test('toDatabase payload contains the keys the dashboard widget consumes', function (): void {
    $user = User::factory()->create();
    $product = buildProductAt85($user);
    $eventId = (string) Str::uuid();

    $payload = new PriceDropNotification($product, buildOutcome(), $eventId)->toDatabase($user);

    expect($payload)->toMatchArray([
        'price_drop_event_id' => $eventId,
        'product_id' => $product->id,
        'title' => 'Acme Headphones',
        'currency' => 'EUR',
        'new_price' => '85.00',
        'host' => 'bol.com',
        'offer_url' => 'https://bol.com/p/headphones',
        'reference_kind' => 'median_30d',
        'drop_percent' => '15.00',
        'drop_absolute' => '15.00',
    ])->and($payload['view_url'])->toBeString();
});

test('via() returns only real-time channels (database + push); never mail', function (): void {
    $product = Product::factory()->create();
    $outcome = buildOutcome();

    // notify_via_email is now decoupled from PriceDropNotification — the
    // toggle drives SendDailyDigest dispatch, not this real-time path.
    $bellOnly = User::factory()->create(['notify_via_email' => true, 'notify_via_filament' => true, 'notify_via_push' => false]);
    $silent = User::factory()->create(['notify_via_email' => true, 'notify_via_filament' => false, 'notify_via_push' => false]);

    $notification = new PriceDropNotification($product, $outcome, 'fake-id');

    expect($notification->via($bellOnly))->toBe(['database'])
        ->and($notification->via($silent))->toBe([])
        ->and($notification->via($bellOnly))->not->toContain('mail');
});

test('via() includes web push only when user has subscriptions AND opted in', function (): void {
    $product = Product::factory()->create();
    $outcome = buildOutcome();

    $optedInNoSubscriptions = User::factory()->create([
        'notify_via_filament' => false,
        'notify_via_push' => true,
    ]);

    $notification = new PriceDropNotification($product, $outcome, 'fake-id');

    // No subscriptions yet → push channel not included.
    expect($notification->via($optedInNoSubscriptions))->toBe([])
        ->and($notification->via($optedInNoSubscriptions))->not->toContain(WebPushChannel::class);
});
