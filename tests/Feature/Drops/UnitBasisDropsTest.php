<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\Reference;
use Illuminate\Support\Facades\Notification;

/**
 * A drop is a fall in what the product costs per kilo, litre or piece.
 *
 * On the pack basis two shops selling different amounts are not comparable, and
 * the app mailed that as news: a 227 g bag of Twix reported 34.4% off a 333 g
 * bag, a 240 g box of fish fingers 61.3% off an 840 g box. Per unit the first is
 * a 3.7% difference and the second is the *dearest* shop on the product.
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function sizedShop(Product $product, string $host, string $price, array $attributes = []): Shop
{
    $shop = Shop::factory()->for($product)->create([
        'url' => "https://{$host}/p/" . fake()->uuid(),
        'currency' => 'EUR',
        'current_price' => $price,
        'current_in_stock' => true,
    ]);

    $shop->forceFill(['current_price' => $price, ...$attributes])->save();

    return $shop;
}

function readingOn(Shop $shop, string $price): PriceCheck
{
    $shop->forceFill(['current_price' => $price])->save();

    return PriceCheck::factory()->for($shop)->create([
        'price' => $price,
        'status' => ScrapeStatus::Ok,
        'in_stock' => true,
    ]);
}

function droppingProduct(string $pct = '3.00'): Product
{
    $user = User::factory()->create();

    return Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => $pct,
        'drop_threshold_abs' => '1000.00',
    ]);
}

it('measures a fall per unit rather than across two pack sizes', function (): void {
    // The Twix shape. jumbo 333 g at 4.19 is 12.58/kg; dirk 227 g at 2.75 is
    // 12.11/kg. On pack money that reads as 34.4% off. It is 3.7%.
    $product = droppingProduct();

    $jumbo = sizedShop($product, 'jumbo.com', '4.19', ['pack_quantity' => '333.00', 'pack_unit' => 'g']);
    readingOn($jumbo, '4.19');
    $product->recomputeCheapestShop();

    // Dirk joins dearer than Jumbo, so nothing fires on the join itself — a new
    // shop is never a fall.
    $dirk = sizedShop($product, 'dirk.nl', '5.00', ['pack_quantity' => '227.00', 'pack_unit' => 'g']);
    $joined = readingOn($dirk, '5.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    // Then its own price falls, and it takes the lead per kilo.
    $trigger = readingOn($dirk, '2.75');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $event = PriceDropEvent::query()->where('product_id', $product->id)->first();

    expect($event)->not->toBeNull()
        ->and(round((float) $event->drop_pct, 1))->toBe(3.7)
        ->and($event->comparison_unit)->toBe('g')
        ->and((string) $event->reference_unit_price)->toBe('12.58')
        ->and((string) $event->new_unit_price)->toBe('12.11');
});

it('detects a drop when the pack price rises and the unit price falls', function (): void {
    // 500 g at 5.00 is 10.00/kg. 1 kg at 8.00 is 8.00/kg — twenty percent
    // better value for three euro more. Asked on the pack price this walks into
    // the recovery branch instead.
    $product = droppingProduct();

    $small = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    readingOn($small, '5.00');
    $product->recomputeCheapestShop();

    $big = sizedShop($product, 'jumbo.com', '10.00', ['pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    $joined = readingOn($big, '10.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    $trigger = readingOn($big, '8.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $event = PriceDropEvent::query()->where('product_id', $product->id)->first();

    expect($event)->not->toBeNull()
        ->and(round((float) $event->drop_pct, 1))->toBe(20.0)
        // The winning pack costs three euro more than the one it displaced.
        ->and((string) $event->new_price)->toBe('8.00');
});

it('reports no saving rather than a negative one when the pack size changes', function (): void {
    // Same move, read as money: 8.00 for the kilo is 3.00 *more* than 5.00 for
    // the half. There is no saving to report, so the column says so.
    $product = droppingProduct();

    $small = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    readingOn($small, '5.00');
    $product->recomputeCheapestShop();

    $big = sizedShop($product, 'jumbo.com', '10.00', ['pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    $joined = readingOn($big, '10.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    $trigger = readingOn($big, '8.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    expect(PriceDropEvent::query()->where('product_id', $product->id)->value('drop_abs'))->toBeNull();
});

it('does not fire when only the pack size was corrected', function (): void {
    // Foodello reported 55 g for a box of twelve. Correcting it to 660 g makes
    // the unit price fall twelvefold at an unchanged price, which is somebody
    // fixing data rather than a shop cutting a price.
    $product = droppingProduct();

    $shop = sizedShop($product, 'foodello.nl', '12.00', ['pack_quantity' => '55.00', 'pack_unit' => 'g']);
    readingOn($shop, '12.00');
    $product->recomputeCheapestShop();

    $shop->forceFill(['pack_quantity' => '660.00'])->save();
    $trigger = readingOn($shop, '12.00');

    $product->refresh()->recomputeCheapestShop($trigger->id);

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);

    Notification::assertNothingSent();

    // History still records the correction: the segment is faithful even though
    // nothing was detected on it.
    expect($product->refresh()->cheapestHistory()->latest('id')->value('pack_quantity'))->not->toBeNull();
});

it('keeps an absolute threshold meaning money off a pack', function (): void {
    // Five euro off is five euro off a pack, not off a kilo. Same 500 g pack
    // either side, so the money figure is honest and the percentage — 20% — sits
    // under this product's 50% percentage threshold.
    $product = droppingProduct('50.00');
    $product->forceFill(['drop_threshold_abs' => '1.00'])->save();

    $shop = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    readingOn($shop, '5.00');
    $product->recomputeCheapestShop();

    $trigger = readingOn($shop, '4.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $event = PriceDropEvent::query()->where('product_id', $product->id)->first();

    expect($event)->not->toBeNull()
        ->and((string) $event->drop_abs)->toBe('1.00')
        ->and((string) $event->new_price)->toBe('4.00');
});

it('clears a latch armed in the other basis instead of suppressing on it', function (): void {
    // A latch holding 6.15 a pack says nothing about 6.15 a kilo. Read as though
    // it did, it would suppress every later fall above that number for good.
    $product = droppingProduct();
    $product->forceFill([
        'last_notified_price' => '6.15',
        'last_notified_unit' => null,
        'last_notified_at' => now()->subDay(),
    ])->save();

    $shop = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    readingOn($shop, '5.00');
    $product->recomputeCheapestShop();

    $trigger = readingOn($shop, '4.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $product->refresh();

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and($product->last_notified_unit)->toBe('g')
        ->and((string) $product->last_notified_price)->toBe('8.00');
});

it('leaves a cross-size drop out of lifetime savings rather than counting nothing as money', function (): void {
    // `drop_abs` is null on those events, and both the monthly chart and the
    // lifetime total sum that column — so the row contributes nothing instead
    // of contributing a number that describes no purchase.
    $product = droppingProduct();

    $small = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    readingOn($small, '5.00');
    $product->recomputeCheapestShop();

    $big = sizedShop($product, 'jumbo.com', '10.00', ['pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    $joined = readingOn($big, '10.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    $trigger = readingOn($big, '8.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $total = PriceDropEvent::query()->where('product_id', $product->id)->sum('drop_abs');

    expect((float) $total)->toBe(0.0)
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('names the shop the drop was measured on, not the smallest outlay', function (): void {
    // The two answers differ here. The alert has to name the shop whose price
    // moved and the price the event row records, or the mail and the row say
    // different things about the same drop.
    $product = droppingProduct();
    $product->user->forceFill(['notify_via_filament' => true])->save();

    $small = sizedShop($product, 'ah.nl', '2.19', ['pack_quantity' => '200.00', 'pack_unit' => 'g']);
    readingOn($small, '2.19');
    $product->recomputeCheapestShop();

    $large = sizedShop($product, 'dirk.nl', '3.00', ['pack_quantity' => '300.00', 'pack_unit' => 'g']);
    $joined = readingOn($large, '3.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    $trigger = readingOn($large, '2.45');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $product->refresh();
    $event = PriceDropEvent::query()->where('product_id', $product->id)->sole();

    expect($product->cheapestShop?->host)->toBe('ah.nl')
        ->and($product->bestValueShopRelation?->host)->toBe('dirk.nl')
        ->and((string) $event->new_price)->toBe('2.45');

    Notification::assertSentTo(
        $product->user,
        PriceDropNotification::class,
        fn (PriceDropNotification $notification): bool => $notification->snapshotHost === 'dirk.nl'
            && $notification->snapshotPrice === '2.45',
    );
});

it('does not carry a reading from another unit into the reference', function (): void {
    // A product whose shops used to be measured in millilitres and now in grams
    // has two scales in its history. A weighted median across the boundary
    // mixes numbers that share none, so the earlier epoch does not contribute.
    $product = droppingProduct();

    $shop = sizedShop($product, 'ah.nl', '5.00', ['pack_quantity' => '500.00', 'pack_unit' => 'ml']);
    readingOn($shop, '5.00');
    $product->recomputeCheapestShop();

    $shop->forceFill(['pack_quantity' => '500.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $reference = app(Reference::class)->compute($product->refresh());

    expect($reference?->unit)->toBe('g');
});

it('never crowns or alerts on a shop whose size was inherited', function (): void {
    // barebells.nl states nothing and inherits 660 g from its siblings. Its
    // price can fall as far as it likes: the size is an assumption, so it may
    // be shown and may not be acted on.
    $product = droppingProduct();

    $ah = sizedShop($product, 'ah.nl', '22.00', ['pack_quantity' => '660.00', 'pack_unit' => 'g']);
    readingOn($ah, '22.00');
    $jumbo = sizedShop($product, 'jumbo.com', '23.00', ['pack_quantity' => '660.00', 'pack_unit' => 'g']);
    readingOn($jumbo, '23.00');
    $product->recomputeCheapestShop();

    $silent = sizedShop($product, 'barebells.nl', '21.00', ['pack_quantity' => null, 'pack_unit' => null]);
    $joined = readingOn($silent, '21.00');
    $product->refresh()->recomputeCheapestShop($joined->id);

    $trigger = readingOn($silent, '15.00');
    $product->refresh()->recomputeCheapestShop($trigger->id);

    $product->refresh();

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and($product->best_value_shop_id)->toBe($ah->id)
        // Still the smallest outlay, and still shown as such.
        ->and($product->cheapestShop?->host)->toBe('barebells.nl');

    Notification::assertNothingSent();
});
