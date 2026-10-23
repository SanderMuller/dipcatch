<?php declare(strict_types=1);

use App\Actions\Shops\SaveOwnerPackSize;
use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * No page states a size: the owner's 12-pack at AH for €21.00 and one bar at
 * Jumbo for €2.99.
 *
 * @return array{Product, Shop, Shop}
 */
function sizelessForOwner(): array
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'share_slug' => str_repeat('o', 32)]);
    $pack = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/12', 'currency' => 'EUR', 'current_price' => '21.00']);
    $bar = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'currency' => 'EUR', 'current_price' => '2.99', 'created_at' => now()->addMinute()]);
    $product->refresh()->recomputeCheapestShop();

    return [$product->refresh(), $pack, $bar];
}

beforeEach(function (): void {
    Notification::fake();
});

it('gives the product a unit and that shop a size that can win, and passes it to no other shop', function (): void {
    [$product, $pack, $bar] = sizelessForOwner();

    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    $product->refresh();
    $packs = $product->comparablePacks();

    expect($packs->unit())->toBe('g')
        ->and($packs->for($pack)?->provenance)->toBe(PackProvenance::Owner)
        ->and($product->best_value_shop_id)->toBe($pack->id)
        ->and($product->best_value_pack_quantity)->toBe('660.00')
        // Not inferred from the owner's size: the bar is another pack.
        ->and($packs->for($bar)?->exclusion)->toBe(PackExclusion::SizeUnknown);
});

it('lets the shop\'s own size win once it states one, and keeps the owner\'s', function (): void {
    [$product, $pack] = sizelessForOwner();
    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    $pack->refresh()->forceFill(['pack_quantity' => '600.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    expect($product->refresh()->comparablePacks()->for($pack)?->provenance)->toBe(PackProvenance::Stated)
        ->and($product->best_value_pack_quantity)->toBe('600.00')
        ->and($pack->refresh()->owner_pack_quantity)->toBe('660.00');
});

it('forgets the owner\'s size when the shop is pointed at another page', function (): void {
    [$product, $pack] = sizelessForOwner();
    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    $pack->refresh()->updateUrl('https://ah.nl/p/other');
    $pack->save();

    expect($pack->refresh()->owner_pack_quantity)->toBeNull()
        ->and($pack->owner_pack_unit)->toBeNull();
});

it('does not read the first price per unit as a drop', function (): void {
    [$product, $pack] = sizelessForOwner();

    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    expect(PriceDropEvent::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('turns a pack target on that shop into a price per kilo, still marked as heard', function (): void {
    [$product, $pack] = sizelessForOwner();
    $product->forceFill(['unit_price_target' => '22.80', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $pack->id])->save();
    $product->forceFill(['unit_price_notified' => '21.00', 'unit_price_notified_unit' => 'pack', 'unit_price_notified_at' => now()])->save();

    app(SaveOwnerPackSize::class)($product->refresh(), $pack->id, '660 g');

    expect($product->refresh()->unit_price_target)->toBe('34.5454')
        ->and($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_notified_unit)->toBe('g');
    Notification::assertNothingSent();
});

it('removes the owner\'s size on an empty input', function (): void {
    [$product, $pack] = sizelessForOwner();
    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    app(SaveOwnerPackSize::class)($product->refresh(), $pack->id, '');

    expect($pack->refresh()->owner_pack_quantity)->toBeNull()
        ->and($product->refresh()->comparablePacks()->unit())->toBeNull();
});

it('refuses a word for the pack instead of what is in it', function (string $input): void {
    [$product, $pack] = sizelessForOwner();

    expect(fn () => app(SaveOwnerPackSize::class)($product, $pack->id, $input))->toThrow(ValidationException::class);
    expect($pack->refresh()->owner_pack_quantity)->toBeNull();
})->with(['a box', '1 pack', '2 packs', 'big']);

it('refuses a shop of another product', function (): void {
    [$product] = sizelessForOwner();
    $elsewhere = Shop::factory()->create();

    expect(fn () => app(SaveOwnerPackSize::class)($product, $elsewhere->id, '660 g'))->toThrow(ValidationException::class);
    expect($elsewhere->refresh()->owner_pack_quantity)->toBeNull();
});

it('prices the shop per kilo on the public page as in the app', function (): void {
    [$product, $pack] = sizelessForOwner();
    app(SaveOwnerPackSize::class)($product, $pack->id, '660 g');

    $html = (string) preg_replace('/\s+/', ' ', $this->get('/p/' . str_repeat('o', 32))->assertOk()->content());

    // The shop columns the page reads include the owner's size.
    expect($html)->toContain('660 g · €31.82 /kg');
});

it('refuses a size the column cannot hold', function (string $input): void {
    [$product, $pack] = sizelessForOwner();

    expect(fn () => app(SaveOwnerPackSize::class)($product, $pack->id, $input))->toThrow(ValidationException::class);
    expect($pack->refresh()->owner_pack_quantity)->toBeNull();
})->with(['100000 kg', '0.001 g']);

it('answers a shop id that is not a uuid as no such shop', function (): void {
    [$product] = sizelessForOwner();

    expect(fn () => app(SaveOwnerPackSize::class)($product, 'not-a-uuid', '660 g'))->toThrow(ValidationException::class);
});
