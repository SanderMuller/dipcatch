<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Mail\DailyDigestMail;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * A "price for any pack" is no longer an alert. It compared the smallest
 * outlay at any shop, so one bar met a price meant for a 12-pack. A product
 * alerts on its price per kilo, litre or piece, or on one shop's pack.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('sends nothing when one bar is under the old price for a 12-pack but far above the price per kilo', function (): void {
    // The digest of 2026-10-23: €34.55 a kilo, and €22.80 for the 12-pack.
    Http::fake([
        'https://jumbo.com/robots.txt' => Http::response('', 404),
        'https://jumbo.com/p/1-bar' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Barebells Protein Bar Creamy Crisp 55 g',
            'offers' => ['@type' => 'Offer', 'price' => '2.99', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'unit_price_target' => '34.55',
        'unit_price_target_unit' => 'g',
        'target_price' => '22.80',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://musclehouse.nl/p/12-pack', 'currency' => 'EUR', 'current_price' => '24.00',
        'pack_quantity' => '660.00', 'pack_unit' => 'g',
    ]);
    $bar = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/1-bar', 'currency' => 'EUR', 'current_price' => '3.49',
        'pack_quantity' => '55.00', 'pack_unit' => 'g',
    ]);

    dispatch_sync(new CheckShopPrice($bar));

    // €2.99 for 55 g is €54.36 a kilo.
    expect($product->refresh()->cheapest_price)->toBe('2.99')
        ->and($product->isAtTarget())->toBeFalse()
        ->and(Product::query()->atTarget()->whereKey($product->id)->exists())->toBeFalse()
        ->and(TargetPriceEvent::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('still renders a pack-price alert from before the change in the daily email', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69']);
    TargetPriceEvent::query()->create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'shop_id' => $shop->id,
        'currency' => 'EUR',
        'target' => '1.75',
        'price' => '1.69',
        'unit_price' => null,
        'comparison_unit' => null,
        'fired_at' => now(),
    ]);
    Mail::swap(app('mail.manager'));

    $html = new DailyDigestMail($user, new EloquentCollection(), TargetPriceEvent::query()->with(['product', 'shop'])->get())->render();

    expect($html)->toContain('Reached your price')
        ->and($html)->toContain('your price €1.75');
});

it('renders a target per pack in the daily email with the pack price once', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->withPackTarget('22.80', ['url' => 'https://ah.nl/p/12', 'current_price' => '21.50'])->create(['currency' => 'EUR']);
    $shop = Shop::query()->findOrFail($product->refresh()->unit_price_target_shop_id);
    TargetPriceEvent::record($product, $shop, '22.8000', price: '21.50', unitPrice: '21.50', comparisonUnit: 'pack');
    Mail::swap(app('mail.manager'));

    $html = new DailyDigestMail($user, new EloquentCollection(), TargetPriceEvent::query()->with(['product', 'shop'])->get())->render();

    expect($html)->toContain('€21.50 /pack')
        ->and($html)->toContain('your price €22.80 /pack');
});
