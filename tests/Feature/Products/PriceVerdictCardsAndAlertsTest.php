<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Actions\Drops\DetectUnitPriceTarget;
use App\Livewire\Dashboard;
use App\Livewire\Products\ProductList;
use App\Mail\DailyDigestMail;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Support\HeadlinePrice;
use App\Support\PriceVerdicts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

use function Pest\Livewire\livewire;

/** @param  array<string, mixed>  $message */
function pushBody(array $message): Stringable
{
    return Str::of(is_string($message['body'] ?? null) ? $message['body'] : '');
}

it('shows the verdict of the headline shop on the product card', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Coffee', 'currency' => 'EUR']);
    $cheap = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.00']);
    priceHistory($cheap, '1.30', days: 90);
    // Dearer, and a high price there: not the shop the card leads with.
    $dear = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '2.00']);
    priceHistory($dear, '1.50', days: 90);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeHtml('data-test="price-verdict"')
        ->assertSee('Good price')
        ->assertSee('Lowest in 90 days')
        ->assertDontSee('High price');
});

it('reads the verdict of the shop the headline names when the pack and unit winners differ', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $pack = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    priceHistory($pack, '2.00', days: 90);
    $unit = Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
    priceHistory($unit, '1.80', days: 90);
    $product->refresh()->recomputeCheapestShop();
    $product->refresh()->load('shops');

    $leader = HeadlinePrice::of($product)->buyableShop();
    $byShop = PriceVerdicts::forShops($product->shops);

    expect($leader)->toBeInstanceOf(Shop::class)
        ->and($byShop[$pack->id]->verdict)->not->toBe($byShop[$unit->id]->verdict)
        ->and(PriceVerdicts::forProducts([$product])[$product->id]->verdict)->toBe($byShop[(string) $leader?->id]->verdict);
});

it('gives no verdict to a product nobody can buy now', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['current_price' => '1.00']);
    priceHistory($shop, '1.30', days: 90);
    $product->refresh()->recomputeCheapestShop();
    $shop->forceFill(['current_in_stock' => false])->save();

    expect(PriceVerdicts::forProducts([$product->refresh()->load('shops')]))->toBeEmpty();
});

it('stores the lowest-in-90-days moment on a target alert, and the digest keeps it after the price moves', function (): void {
    Notification::fake();
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69']);
    $product->forceFill(['unit_price_target' => '1.75', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $shop->id])->save();
    priceHistory($shop, '2.00', days: 90);
    $product->refresh()->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product->refresh());

    $event = TargetPriceEvent::query()->sole();
    expect($event->lowest_in_90_days)->toBeTrue();
    Notification::assertSentTo($user, UnitPriceTargetNotification::class, fn (UnitPriceTargetNotification $notification): bool => pushBody($notification->toWebPush($user)->toArray())->endsWith(' · lowest in 90 days'));

    $shop->forceFill(['current_price' => '2.40'])->save();
    PriceCheck::factory()->for($shop)->create(['price' => '2.40', 'checked_at' => now()]);
    Mail::swap(app('mail.manager'));

    $html = new DailyDigestMail($user, new EloquentCollection(), TargetPriceEvent::query()->with(['product', 'shop'])->get())->render();

    expect($html)->toContain('Lowest in 90 days');
});

it('stores no lowest-in-90-days moment on an alert with too little history', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69']);
    $product->forceFill(['unit_price_target' => '1.75', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $shop->id])->save();
    priceHistory($shop, '2.00', days: 20);
    $product->refresh()->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product->refresh());

    expect(TargetPriceEvent::query()->sole()->lowest_in_90_days)->toBeFalse();
    Notification::assertSentTo($user, UnitPriceTargetNotification::class, fn (UnitPriceTargetNotification $notification): bool => ! pushBody($notification->toWebPush($user)->toArray())->contains('lowest in 90 days'));
});

it('stores the lowest-in-90-days moment on a price drop and says it in the push', function (): void {
    Notification::fake();
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['current_price' => '70.00']);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '70.00', 'drop_threshold_pct' => '10.00', 'drop_threshold_abs' => '5.00'])->save();

    foreach (range(1, 8) as $i) {
        ProductCheapestHistory::factory()->for($product)->create(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '100.00', 'started_at' => now()->subDays(20 + $i), 'ended_at' => now()->subDays(19 + $i)]);
    }

    priceHistory($shop, '100.00', days: 90);
    $check = PriceCheck::query()->where('shop_id', $shop->id)->latest('checked_at')->firstOrFail();

    app(DetectDrop::class)($product, $check->id);

    $user = $product->user;
    expect(PriceDropEvent::query()->sole()->lowest_in_90_days)->toBeTrue();
    Notification::assertSentTo($user, PriceDropNotification::class, fn (PriceDropNotification $notification): bool => $user instanceof User
        && pushBody($notification->toWebPush($user)->toArray())->endsWith(' · lowest in 90 days'));
});

it('shows the verdict on a dashboard card', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Coffee', 'currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.00']);
    $product->forceFill(['unit_price_target' => '1.50', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $shop->id])->save();
    priceHistory($shop, '1.30', days: 90);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeHtml('data-test="price-verdict"')
        ->assertSee('Good price');
});

it('says lowest in 90 days on a drop in the digest only when the event stored it', function (bool $lowest): void {
    Mail::swap(app('mail.manager'));
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Digest product']);
    $shop = Shop::factory()->for($product)->create();
    PriceDropEvent::factory()->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '1.69',
        'drop_abs' => '0.50',
        'drop_pct' => '22.8',
        'lowest_in_90_days' => $lowest,
    ]);

    $html = new DailyDigestMail($user, PriceDropEvent::query()->get())->render();

    expect(str_contains($html, 'Lowest in 90 days'))->toBe($lowest);
})->with(['stored' => true, 'not stored' => false]);

it('says lowest in 90 days in a unit-price alert push when the event stored it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Crisps']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);

    $with = new UnitPriceTargetNotification($product, $shop, '8.4500', '9.00', lowestIn90Days: true);
    $without = new UnitPriceTargetNotification($product, $shop, '8.4500', '9.00');

    expect(pushBody($with->toWebPush($user)->toArray())->endsWith(' · lowest in 90 days'))->toBeTrue()
        ->and(pushBody($without->toWebPush($user)->toArray())->contains('lowest in 90 days'))->toBeFalse();
});
