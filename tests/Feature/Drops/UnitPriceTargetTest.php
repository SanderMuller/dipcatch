<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\Drops\NotificationBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A product tracked at two shops: a 200 g bag and a 370 g bag. The owner is
 * on Pro, because the unit-price target is a Pro feature — the free-plan
 * behaviour has its own test in tests/Feature/Billing.
 */
function targetProduct(?string $target, string $lidlPrice = '1.99'): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => $target,
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1', 'currency' => 'EUR', 'current_price' => $lidlPrice,
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    return $product->refresh();
}

beforeEach(function (): void {
    Notification::fake();
});

test('reaching the target notifies', function (): void {
    // €1.99 for 370 g is €5.38/kg, at or under a €5.50/kg target.
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertSentTo($product->user, UnitPriceTargetNotification::class);
    expect($product->refresh()->unit_price_notified)->toBe('5.3784');

    // The daily email reads this row, not the notification.
    $event = TargetPriceEvent::query()->sole();
    expect($event->isPerUnit())->toBeTrue()
        ->and((string) $event->unit_price)->toBe('5.3784')
        ->and($event->comparison_unit)->toBe('g')
        ->and((string) $event->price)->toBe('1.99')
        ->and($event->packSize()?->quantity)->toBe(370.0)
        ->and((string) $event->target)->toBe('5.5000');
});

test('a value above the target says nothing', function (): void {
    $product = targetProduct('5.00');

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
    expect($product->refresh()->unit_price_notified)->toBeNull();
});

test('clearing the target clears a stale latch', function (): void {
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);
    expect($product->refresh()->unit_price_notified)->not->toBeNull();

    $product->forceFill(['unit_price_target' => null])->save();

    expect($product->refresh()->unit_price_notified)->toBeNull();
});

test('raising the target past the old latch lets the alert fire again', function (): void {
    $product = targetProduct('5.50');

    // €1.99 for 370 g arms the latch at 5.38/kg for real.
    app(DetectUnitPriceTarget::class)($product);
    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class);

    // Raise the target without the price moving. Under the stale latch,
    // 5.38 "already notified" suppresses every check until the price falls
    // further — the owner asked to hear about anything at 6.00/kg or less
    // and hears nothing.
    $product->forceFill(['unit_price_target' => '6.00'])->save();

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class, 2);
});

test('a save that leaves the target untouched does not re-arm the latch', function (): void {
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);
    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class);

    // Neither of these touches unit_price_target; the latch must survive.
    $product->forceFill(['active' => false])->save();
    $product->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class);
});

test('re-saving the same target value does not clear the latch', function (): void {
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);
    expect($product->refresh()->unit_price_notified)->not->toBeNull();

    $product->forceFill(['unit_price_target' => '5.50'])->save();

    expect($product->refresh()->unit_price_notified)->not->toBeNull();
});

test('a target and its latch set together at creation both survive', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => '5.50',
        'unit_price_notified' => '5.38',
        'unit_price_notified_at' => now(),
    ]);

    expect($product->unit_price_notified)->not->toBeNull();
});

test('a product with no target is left alone', function (): void {
    $product = targetProduct(null);

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
});

test('the same value does not notify twice', function (): void {
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);
    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class);
});

test('a further drop is news again', function (): void {
    $product = targetProduct('5.50');
    app(DetectUnitPriceTarget::class)($product);

    // €1.79 for 370 g is €4.84/kg — cheaper than what was already sent.
    $product->shops()->where('host', 'lidl.nl')->update(['current_price' => '1.79']);

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class, 2);
});

test('rising back above the target arms the alert again', function (): void {
    $product = targetProduct('5.50');
    app(DetectUnitPriceTarget::class)($product);

    $product->shops()->where('host', 'lidl.nl')->update(['current_price' => '2.49']);
    app(DetectUnitPriceTarget::class)($product->refresh());

    expect($product->refresh()->unit_price_notified)->toBeNull();

    $product->shops()->where('host', 'lidl.nl')->update(['current_price' => '1.99']);
    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class, 2);
});

test('a product whose shops state no pack size cannot reach a target', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'unit_price_target' => '5.50']);
    Shop::factory()->for($product)->create([
        'url' => 'https://dataset.test/p/1', 'currency' => 'EUR', 'current_price' => '0.99',
    ]);

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertNothingSent();
});

test('the message states the unit price, the pack price and the shop', function (): void {
    // Built here rather than read off nullable accessors, so the message is
    // asserted against known values.
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'unit_price_target' => '5.50']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    $payload = new UnitPriceTargetNotification($product, $shop, '5.38')->toDatabase($user);

    expect($payload['unit_price'])->toBe('5.38')
        ->and($payload['unit_price_label'])->toBe('/kg')
        ->and($payload['new_price'])->toBe('1.99')
        ->and($payload['host'])->toBe('lidl.nl')
        ->and($payload['unit_price_target'])->toBe('5.5000');
});

test('a price check on any shop can fire the target, not only the cheapest', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Chips',
            'offers' => ['@type' => 'Offer', 'price' => '1.99', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'unit_price_target' => '5.50']);

    // The cheapest shop, which this check does not touch.
    $cheapest = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $cheapest->id, 'cheapest_price' => '1.69'])->save();

    // The bigger bag, whose price is what the check updates.
    $value = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1', 'currency' => 'EUR', 'current_price' => '2.49',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    dispatch_sync(new CheckShopPrice($value));

    // The cheapest price never moved, so the drop engine saw nothing — but
    // the best value fell to €5.38/kg.
    expect($product->refresh()->cheapest_price)->toBe('1.69');
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});

test('the unit-price alert obeys the same hourly ceiling as a drop alert', function (): void {
    // The owner is on Pro, so it is the Pro ceiling that must bind.
    config()->set('plans.pro.notifications_hourly_limit', 1);

    $product = targetProduct('5.50');
    $user = $product->user()->sole();

    // Spend the single hourly allowance.
    RateLimiter::hit(NotificationBudget::key($user), 3600);

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
});

test('a value that only rounds onto the target does not fire', function (): void {
    // 12.99 for 400 tablets is 0.032475 each — above a 0.03 target, and
    // indistinguishable from it once rounded for display.
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => '0.03',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_price' => '12.99',
        'pack_quantity' => '400.00', 'pack_unit' => 'piece',
    ]);

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertNothingSent();
    expect($product->refresh()->unit_price_notified)->toBeNull();
});

test('a target can be set between two values a cent apart', function (): void {
    // 0.028 sits between the 400-pack's 0.032475 and the 800-pack's
    // 0.0274875. At two decimals both were 0.03 and no target could separate
    // them, so the field could not express what the shopper wanted.
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => '0.0280',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://benushop.nl/p/1', 'currency' => 'EUR', 'current_price' => '21.99',
        'pack_quantity' => '800.00', 'pack_unit' => 'piece',
    ]);

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentTo($product->user, UnitPriceTargetNotification::class);
    expect($product->refresh()->unit_price_notified)->toBe('0.0275');
});

test('the alert leads with the unit price, stores its unit code and names the pack', function (): void {
    $product = targetProduct('5.50');

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertSentTo($product->user, UnitPriceTargetNotification::class, function (UnitPriceTargetNotification $notification) use ($product): bool {
        $user = $product->user;
        assert($user instanceof User);
        $payload = $notification->toDatabase($user);

        $body = $notification->toWebPush($user)->toArray()['body'] ?? null;

        return $payload['unit'] === 'g'
            && $payload['pack_unit'] === 'g'
            && is_string($body) && str_contains($body, 'is €5.38 /kg (€1.99 for 370 g) at lidl.nl');
    });
});
