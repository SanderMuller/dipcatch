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

    Notification::assertSentToTimes($product->user, TargetPriceNotification::class);

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

test('clearing the target clears a stale latch', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);
    expect($product->refresh()->target_price_notified)->not->toBeNull();

    $product->forceFill(['target_price' => null])->save();

    expect($product->refresh()->target_price_notified)->toBeNull();
});

test('raising the target past the old latch lets the alert fire again', function (): void {
    $product = targetPriceProduct('18.00');

    // Arms the latch at the real price for real: this is not a column
    // assertion, it is the shopper actually getting told once.
    app(DetectTargetPrice::class)($product);
    Notification::assertSentToTimes($product->user, TargetPriceNotification::class);

    // Raise the target well past the price the latch fired at. Without the
    // clear, the stale latch (17.05) still beats any price down to 17.05,
    // so the owner who asked to hear about anything under 30 hears nothing
    // until the price falls under 17.05 again.
    $product->forceFill(['target_price' => '30.00'])->save();

    app(DetectTargetPrice::class)($product->refresh());

    Notification::assertSentToTimes($product->user, TargetPriceNotification::class, 2);
});

test('a save that leaves the target untouched does not re-arm the latch', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);
    Notification::assertSentToTimes($product->user, TargetPriceNotification::class);

    // Neither of these touches target_price; the latch must survive both.
    $product->forceFill(['active' => false])->save();
    $product->recomputeCheapestShop();

    app(DetectTargetPrice::class)($product->refresh());

    Notification::assertSentToTimes($product->user, TargetPriceNotification::class);
});

test('re-saving the same target value does not clear the latch', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);
    expect($product->refresh()->target_price_notified)->not->toBeNull();

    // Same value, re-typed: decimal-cast comparison must see this as
    // unchanged, not dirty.
    $product->forceFill(['target_price' => '18.00'])->save();

    expect($product->refresh()->target_price_notified)->not->toBeNull();
});

test('a target and its latch set together at creation both survive', function (): void {
    // Every attribute is dirty on insert. Without the update-only guard,
    // the hook would wipe this latch before the row is ever written.
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'target_price' => '18.00',
        'target_price_notified' => '17.05',
        'target_price_notified_at' => now(),
    ]);

    expect($product->target_price_notified)->not->toBeNull();
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

test('set_threshold changing the target clears a stale latch', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $me->id,
        'target_price' => '18.00',
        'target_price_notified' => '17.05',
        'target_price_notified_at' => now(),
    ]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'target_price' => 30])
        ->assertOk();

    $fresh = $product->fresh();

    expect((float) $fresh?->target_price)->toBe(30.0)
        ->and($fresh?->target_price_notified)->toBeNull()
        ->and($fresh?->target_price_notified_at)->toBeNull();
});

test('set_threshold re-sending the same target through the tool does not clear the latch', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $me->id,
        'target_price' => '18.00',
        'target_price_notified' => '17.05',
        'target_price_notified_at' => now(),
    ]);

    DipCatchServer::actingAs($me)
        // The tool assigns a float; the stored value is a decimal-cast
        // string. This proves that type difference does not read as dirty.
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'target_price' => 18])
        ->assertOk();

    $fresh = $product->fresh();

    expect($fresh?->target_price_notified)->toBe('17.05');
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

test('a target fires from the smallest outlay even when another shop is better value', function (): void {
    // The Lay's case, where the two answers genuinely differ: ah sells 200 g at
    // 2.19 (10.95/kg), dirk 300 g at 2.45 (8.17/kg). A target is a buy trigger —
    // "tell me when I can get one for under 2.30" — so it answers on outlay, and
    // no ranking change can move it.
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'target_price' => '2.30']);

    foreach ([
        ['host' => 'ah.nl', 'price' => '2.19', 'quantity' => '200.00'],
        ['host' => 'dirk.nl', 'price' => '2.45', 'quantity' => '300.00'],
    ] as $row) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $row['host'] . '/p/1'])
            ->forceFill([
                'currency' => 'EUR',
                'current_price' => $row['price'],
                'current_in_stock' => true,
                'pack_quantity' => $row['quantity'],
                'pack_unit' => 'g',
            ])->save();
    }

    $product->refresh()->recomputeCheapestShop();
    $product->refresh();

    expect($product->cheapestShop?->host)->toBe('ah.nl')
        ->and($product->bestValueShop()?->host)->toBe('dirk.nl');

    app(DetectTargetPrice::class)($product);

    Notification::assertSentTo($product->user, TargetPriceNotification::class);
    expect((string) $product->refresh()->target_price_notified)->toBe('2.19');
});

test('the alert leads with the pack price that fired and names a better buy per unit', function (): void {
    // The target is a pack amount, reached by the small pack. Per kilo the big
    // pack elsewhere is the better buy, and the alert says so.
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps', 'target_price' => '1.75']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
    $product->recomputeCheapestShop();

    app(DetectTargetPrice::class)($product->refresh());

    Notification::assertSentTo($user, TargetPriceNotification::class, function (TargetPriceNotification $notification) use ($user): bool {
        $payload = $notification->toDatabase($user);

        return $notification->toWebPush($user)->toArray()['body']
                === 'Crisps is €1.69 for 200 g at ah.nl · your target €1.75 · €8.45 /kg · better value €5.38 /kg at lidl.nl'
            && $payload['unit_price'] === '8.4500'
            && $payload['unit'] === 'g'
            && $payload['better_value_host'] === 'lidl.nl';
    });
});

test('the alert names no better buy when the shop that fired is also the best value', function (): void {
    $product = targetPriceProduct('18.00');

    app(DetectTargetPrice::class)($product);

    Notification::assertSentTo($product->user, TargetPriceNotification::class, function (TargetPriceNotification $notification) use ($product): bool {
        $user = $product->user;
        assert($user instanceof User);

        $body = $notification->toWebPush($user)->toArray()['body'] ?? null;

        return $notification->toDatabase($user)['better_value_host'] === null
            && is_string($body) && ! str_contains($body, 'better value');
    });
});

test('the alert states the target it reached, even when the target changes before delivery', function (): void {
    $product = targetPriceProduct('18.00');
    $user = $product->user;
    assert($user instanceof User);

    $notification = new TargetPriceNotification($product, $product->cheapestShop()->firstOrFail(), '17.05');
    $product->forceFill(['target_price' => '12.00'])->save();

    $revived = unserialize(serialize($notification));
    assert($revived instanceof TargetPriceNotification);

    expect($revived->toWebPush($user)->toArray()['body'])->toContain('your target €18.00')
        ->and($revived->toDatabase($user)['target_price'])->toBe('18.00');
});

test('the alert states no unit price for a shop whose size is only estimated', function (): void {
    // The sized shop is sold out, so nothing can win per unit; the shop that
    // reached the target states no size of its own.
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps', 'target_price' => '2.00']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'current_in_stock' => false]);
    $silent = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '1.79', 'pack_quantity' => null, 'pack_unit' => null]);
    $product->recomputeCheapestShop();

    $notification = new TargetPriceNotification($product->refresh(), $silent, '1.79');

    expect($notification->toDatabase($user)['unit_price'])->toBeNull()
        ->and($notification->toWebPush($user)->toArray()['body'])->not->toContain('/kg');
});

test('the better-buy note keeps its unit when the shop that fired has no size of its own', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps', 'target_price' => '1.75']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    $silent = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '1.49', 'pack_quantity' => null, 'pack_unit' => null]);
    $product->recomputeCheapestShop();

    $body = new TargetPriceNotification($product->refresh(), $silent, '1.49')->toWebPush($user)->toArray()['body'];

    expect($body)->toContain('better value €5.38 /kg at lidl.nl')
        ->not->toContain('· €7.45');
});
