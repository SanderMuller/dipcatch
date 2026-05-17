<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropOutcome;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

function dropOutcome(): DropOutcome
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

test('database channel writes a row with the price_drop_event_id payload', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => 'Acme Headphones',
    ]);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/x',
        'current_price' => '85.00',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '85.00'])->save();

    $eventId = (string) Str::uuid();

    $user->notify(new PriceDropNotification($product, dropOutcome(), $eventId));

    /** @var DatabaseNotification $row */
    $row = $user->notifications()->firstOrFail();

    expect($row->type)->toBe(PriceDropNotification::class)
        ->and($row->read_at)->toBeNull();

    /** @var array<string, mixed> $data */
    $data = (array) $row->data;

    expect($data)->toMatchArray([
        'price_drop_event_id' => $eventId,
        'product_id' => $product->id,
        'title' => 'Acme Headphones',
        'currency' => 'EUR',
        'reference_kind' => 'median_30d',
    ])->and($data['view_url'])->toBeString();
});

test('marking a notification as read sets read_at and removes it from unreadNotifications', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => true,
    ]);
    $product = Product::factory()->for($user)->create();

    $user->notify(new PriceDropNotification($product, dropOutcome(), (string) Str::uuid()));

    expect($user->unreadNotifications()->count())->toBe(1);

    $user->unreadNotifications->first()->markAsRead();

    $user->refresh();
    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($user->notifications()->whereNotNull('read_at')->count())->toBe(1);
});

test('users with notify_via_filament=false do not receive a database row', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => false,
    ]);
    $product = Product::factory()->for($user)->create();

    $user->notify(new PriceDropNotification($product, dropOutcome(), (string) Str::uuid()));

    expect($user->notifications()->count())->toBe(0);
});
