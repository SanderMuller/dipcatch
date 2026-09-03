<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * A product tracked at two shops: a 200 g bag and a 370 g bag.
 */
function targetProduct(?string $target, string $lidlPrice = '1.99'): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
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
    expect($product->refresh()->unit_price_notified)->toBe('5.38');
});

test('a value above the target says nothing', function (): void {
    $product = targetProduct('5.00');

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
    expect($product->refresh()->unit_price_notified)->toBeNull();
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

    Notification::assertSentToTimes($product->user, UnitPriceTargetNotification::class, 1);
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
        ->and($payload['unit_price_target'])->toBe('5.50');
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

    CheckShopPrice::dispatchSync($value);

    // The cheapest price never moved, so the drop engine saw nothing — but
    // the best value fell to €5.38/kg.
    expect($product->refresh()->cheapest_price)->toBe('1.69');
    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});
