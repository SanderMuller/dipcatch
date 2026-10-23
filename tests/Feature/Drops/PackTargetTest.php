<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * A product with no size anywhere: the owner's 12-pack at €24.00, and one
 * bar at another shop for €2.99. The target is €22.80 for the 12-pack.
 *
 * @return array{Product, Shop, Shop}
 */
function packTargetProduct(string $target = '22.80'): array
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $pinned = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/12-pack', 'currency' => 'EUR', 'current_price' => '24.00',
    ]);
    $single = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/1-bar', 'currency' => 'EUR', 'current_price' => '2.99',
        'created_at' => now()->addMinute(),
    ]);

    $product->forceFill([
        'unit_price_target' => $target,
        'unit_price_target_unit' => 'pack',
        'unit_price_target_shop_id' => $pinned->id,
    ])->save();

    return [$product->refresh(), $pinned, $single];
}

function atTargetInSql(Product $product): bool
{
    return Product::query()->atTarget()->whereKey($product->id)->exists();
}

beforeEach(function (): void {
    Notification::fake();
});

it('alerts per pack when the pinned shop reaches the price', function (): void {
    [$product, $pinned] = packTargetProduct();
    $pinned->forceFill(['current_price' => '21.50'])->save();

    app(DetectUnitPriceTarget::class)($product->refresh());

    $user = $product->user()->sole();
    Notification::assertSentTo($user, UnitPriceTargetNotification::class, function (UnitPriceTargetNotification $notification) use ($user): bool {
        $payload = $notification->toDatabase($user);

        return $payload['unit'] === 'pack' && $payload['unit_price'] === '21.50' && $payload['host'] === 'ah.nl'
            && str_contains((string) json_encode($notification->toWebPush($user)->toArray()), 'Target price per pack');
    });

    $event = TargetPriceEvent::query()->sole();
    expect($event->comparison_unit)->toBe('pack')
        ->and($event->shop_id)->toBe($pinned->id)
        ->and($event->unit_price)->toBe('21.5000')
        ->and($product->refresh()->unit_price_notified_unit)->toBe('pack')
        ->and($product->isAtTarget())->toBeTrue()
        ->and(atTargetInSql($product))->toBeTrue();
});

it('does not alert on a cheaper pack at another shop', function (): void {
    // The reported case: one 55 g bar at €2.99 is under €22.80, and is not a 12-pack.
    [$product] = packTargetProduct();

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
    expect($product->isAtTarget())->toBeFalse()
        ->and($product->hasActiveTarget())->toBeTrue()
        ->and(atTargetInSql($product))->toBeFalse();
});

it('suspends the target when the pinned shop is removed', function (): void {
    [$product, $pinned] = packTargetProduct('30.00');

    $pinned->delete();
    $product->refresh();

    expect($product->unit_price_target_shop_id)->toBeNull()
        ->and($product->isUnitTargetSuspended())->toBeTrue()
        ->and($product->hasActiveTarget())->toBeFalse()
        ->and(atTargetInSql($product))->toBeFalse();

    app(DetectUnitPriceTarget::class)($product);
    Notification::assertNothingSent();
});

it('suspends the target while the pinned shop cannot be bought from', function (Closure $makeUnbuyable): void {
    [$product, $pinned] = packTargetProduct('30.00');
    $makeUnbuyable($pinned);
    $product->refresh();

    expect($product->isUnitTargetSuspended())->toBeTrue()
        ->and($product->isAtTarget())->toBeFalse()
        ->and(atTargetInSql($product))->toBeFalse();

    app(DetectUnitPriceTarget::class)($product);
    Notification::assertNothingSent();
})->with([
    'sold out' => [fn (Shop $shop): bool => $shop->forceFill(['current_in_stock' => false])->save()],
    'dead' => [fn (Shop $shop): bool => $shop->forceFill(['active' => false, 'health' => 'dead'])->save()],
    'paused' => [fn (Shop $shop): bool => $shop->forceFill(['active' => false])->save()],
    'another currency' => [fn (Shop $shop): bool => $shop->forceFill(['currency' => 'GBP'])->save()],
    'a price without VAT' => [fn (Shop $shop): bool => $shop->forceFill(['consumer_price_issue' => 'excludes_vat'])->save()],
    'a link' => [fn (Shop $shop): bool => $shop->forceFill(['kind' => 'reference'])->save()],
]);

it('turns into a price per unit once the pinned shop states its own size, and is not sent again', function (): void {
    [$product, $pinned] = packTargetProduct();
    // The owner heard about this shop's €21.00 under the target per pack.
    $product->forceFill(['unit_price_notified' => '21.00', 'unit_price_notified_unit' => 'pack', 'unit_price_notified_at' => now()])->save();
    Log::spy();
    $pinned->forceFill(['pack_quantity' => '660.00', 'pack_unit' => 'g', 'current_price' => '21.00'])->save();

    $product->refresh()->recomputeCheapestShop();

    // €22.80 for 660 g is €34.5454 a kilo, cut, never rounded up.
    expect($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_target)->toBe('34.5454')
        ->and($product->unit_price_target_shop_id)->toBeNull()
        // The €21.00 the owner heard about, for 660 g: still heard.
        ->and($product->unit_price_notified)->toBe('31.8181')
        ->and($product->unit_price_notified_unit)->toBe('g');

    Log::shouldHaveReceived('info')->with('Pack target turned into a price per unit', Mockery::on(
        fn (array $context): bool => $context['pack_target'] === '22.8000' && $context['unit_target'] === '34.5454',
    ))->once();

    app(DetectUnitPriceTarget::class)($product->refresh());
    Notification::assertNothingSent();

    $product->recomputeCheapestShop();
    expect($product->unit_price_target)->toBe('34.5454');
});

it('sends an offer under the converted target that the owner never heard about', function (): void {
    [$product, $pinned] = packTargetProduct();
    // The size arrives in the same check as a price under the target.
    $pinned->forceFill(['pack_quantity' => '660.00', 'pack_unit' => 'g', 'current_price' => '21.00'])->save();

    $product->refresh()->recomputeCheapestShop();

    expect($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_notified)->toBeNull();

    app(DetectUnitPriceTarget::class)($product->refresh());
    Notification::assertSentTo($product->user()->sole(), UnitPriceTargetNotification::class);
});

it('stays on the pinned shop when another shop gets a size and wins', function (): void {
    [$product, $pinned, $single] = packTargetProduct();
    $single->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g'])->save();

    $product->refresh()->recomputeCheapestShop();

    // The bar wins per kilo, and its 55 g is not the size of the 12-pack.
    expect($product->best_value_shop_id)->toBe($single->id)
        ->and($product->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($pinned->id)
        ->and($product->unit_price_target)->toBe('22.8000')
        ->and($product->isAtTarget())->toBeFalse();

    $pinned->forceFill(['current_price' => '22.00'])->save();

    expect($product->refresh()->isAtTarget())->toBeTrue()
        ->and(atTargetInSql($product))->toBeTrue();
});

it('treats a pack target moved to another shop at the same number as a new target', function (): void {
    [$product, $pinned, $single] = packTargetProduct();
    $pinned->forceFill(['current_price' => '22.00'])->save();
    app(DetectUnitPriceTarget::class)($product->refresh());

    $single->forceFill(['current_price' => '22.00'])->save();
    $product->forceFill(['unit_price_target_shop_id' => $single->id])->save();

    expect($product->unit_price_notified)->toBeNull();

    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentToTimes($product->user()->sole(), UnitPriceTargetNotification::class, 2);
    expect(TargetPriceEvent::query()->pluck('shop_id')->all())->toEqualCanonicalizing([$pinned->id, $single->id]);
});

it('keeps a pack target live while the product has a unit but no winner, and suspends a target per kilo', function (): void {
    [$product, $pinned, $single] = packTargetProduct();
    // The only sized shop sold out: the product still compares per kilo, and nobody can win.
    $single->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g', 'current_in_stock' => false])->save();
    $pinned->forceFill(['current_price' => '22.00'])->save();
    $product->refresh()->recomputeCheapestShop();

    expect($product->comparablePacks()->unit())->toBe('g')
        ->and($product->bestValueShop())->toBeNull()
        ->and($product->hasActiveTarget())->toBeTrue()
        ->and($product->isAtTarget())->toBeTrue();

    $product->forceFill(['unit_price_target' => '34.55', 'unit_price_target_unit' => 'g', 'unit_price_target_shop_id' => null])->save();

    expect($product->refresh()->hasActiveTarget())->toBeFalse()
        ->and($product->isAtTarget())->toBeFalse();
});

it('lets go of the latch while the pinned shop is sold out, so its return is news again', function (): void {
    [$product, $pinned] = packTargetProduct();
    $pinned->forceFill(['current_price' => '21.50'])->save();
    app(DetectUnitPriceTarget::class)($product->refresh());

    $pinned->forceFill(['current_in_stock' => false])->save();
    app(DetectUnitPriceTarget::class)($product->refresh());

    expect($product->refresh()->unit_price_notified)->toBeNull();
});

it('does not turn a pack target into a price per unit on a size in another unit', function (): void {
    [$product, $pinned, $single] = packTargetProduct();
    $single->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g'])->save();
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/2', 'currency' => 'EUR', 'current_price' => '5.49', 'pack_quantity' => '110.00', 'pack_unit' => 'g']);
    // The pinned shop counts its pack in bars, while the product compares per kilo.
    $pinned->forceFill(['pack_quantity' => '12.00', 'pack_unit' => 'piece'])->save();

    $product->refresh()->recomputeCheapestShop();

    expect($product->comparablePacks()->unit())->toBe('g')
        ->and($product->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($pinned->id);
});

it('sends a lower offer that arrives with the pinned shop\'s size, after the owner heard a higher one', function (): void {
    [$product, $pinned] = packTargetProduct();
    $product->forceFill(['unit_price_notified' => '22.00', 'unit_price_notified_unit' => 'pack', 'unit_price_notified_at' => now()])->save();
    $pinned->forceFill(['pack_quantity' => '660.00', 'pack_unit' => 'g', 'current_price' => '20.00'])->save();

    $product->refresh()->recomputeCheapestShop();
    app(DetectUnitPriceTarget::class)($product->refresh());

    // Heard at €22.00 for 660 g, €33.3333 a kilo; €20.00 is €30.3030.
    Notification::assertSentTo($product->user()->sole(), UnitPriceTargetNotification::class);
});
