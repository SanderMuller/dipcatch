<?php declare(strict_types=1);

use App\Livewire\Products\AddProductWizard;
use App\Livewire\Products\EditProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\UnitTargetGuide;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

/**
 * The 12-pack at €21.00 for 660 g wins at €31.8181… a kilo; one 55 g bar sells
 * for €2.99 elsewhere.
 *
 * @return array{User, Product, Shop, Shop}
 */
function alertFormProduct(bool $sized = true): array
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $big = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/12', 'currency' => 'EUR', 'current_price' => '21.00',
        ...($sized ? ['pack_quantity' => '660.00', 'pack_unit' => 'g'] : []),
    ]);
    $bar = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/1', 'currency' => 'EUR', 'current_price' => '2.99', 'created_at' => now()->addMinute(),
        ...($sized ? ['pack_quantity' => '55.00', 'pack_unit' => 'g'] : []),
    ]);
    $product->refresh()->recomputeCheapestShop();

    return [$user, $product->refresh(), $big, $bar];
}

it('saves the price for my pack as a price per kilo', function (): void {
    [$user, $product] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSet('unitPriceTargetUnit', 'g')
        ->set('unitPriceTarget', '34.5454')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target)->toBe('34.5454')
        ->and($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_target_shop_id)->toBeNull();
});

it('works a percentage under today\'s best out on the server', function (): void {
    [$user, $product, $big] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('chosenPackShopId', $big->id)
        ->set('alertHelper', 'percent')
        ->set('alertHelperValue', '10')
        ->set('unitPriceTarget', '99')
        ->call('save')
        ->assertHasNoErrors();

    // 10% under €31.8181… a kilo, cut to four decimals.
    expect($product->refresh()->unit_price_target)->toBe('28.6363');
});

it('works an amount under my pack\'s price out on the server', function (): void {
    [$user, $product, $big] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('chosenPackShopId', $big->id)
        ->set('alertHelper', 'amount')
        ->set('alertHelperValue', '2.00')
        ->call('save')
        ->assertHasNoErrors();

    // €19.00 for 660 g.
    expect($product->refresh()->unit_price_target)->toBe('28.7878');
});

it('refuses an amount that takes the whole price of my pack', function (): void {
    [$user, $product, $big] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('chosenPackShopId', $big->id)
        ->set('alertHelper', 'amount')
        ->set('alertHelperValue', '21.00')
        ->call('save')
        ->assertHasErrors('alertHelperValue');

    expect($product->refresh()->unit_price_target)->toBeNull();
});

it('shows the pack chip even when every pack has the same size', function (): void {
    [$user, $product] = alertFormProduct();
    $product->shops()->update(['pack_quantity' => '660.00', 'pack_unit' => 'g']);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('Which pack do you buy?')
        ->assertSeeHtml('data-test="unit-target-helpers"')
        ->assertSee('No target: we tell you when the price drops well under its usual price.');
});

it('saves a price for one shop\'s pack while no shop has a size', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSet('unitPriceTargetUnit', 'pack')
        ->assertSeeHtml('data-test="pack-target"')
        ->assertSee('Price for this pack')
        ->set('chosenPackShopId', $big->id)
        ->set('unitPriceTarget', '22.80')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target)->toBe('22.8000')
        ->and($product->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($big->id)
        ->and($product->isAtTarget())->toBeTrue();
});

it('moves a pack target to another shop as a new target', function (): void {
    [$user, $product, $big, $bar] = alertFormProduct(sized: false);
    $product->forceFill([
        'unit_price_target' => '22.80', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $big->id,
        'unit_price_notified' => '21.00', 'unit_price_notified_unit' => 'pack', 'unit_price_notified_at' => now(),
    ])->save();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSet('chosenPackShopId', $big->id)
        ->assertSet('unitPriceTarget', '22.8')
        ->set('chosenPackShopId', $bar->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target_shop_id)->toBe($bar->id)
        ->and($product->unit_price_notified)->toBeNull();
});

it('refuses a pack of another product', function (): void {
    [$user, $product] = alertFormProduct(sized: false);
    $elsewhere = Shop::factory()->for(Product::factory()->for($user))->create();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('chosenPackShopId', $elsewhere->id)
        ->set('unitPriceTarget', '22.80')
        ->call('save')
        ->assertHasErrors('chosenPackShopId');

    expect($product->refresh()->unit_price_target)->toBeNull();
});

it('names a pack target that waits for its shop\'s size on a product that compares per kilo', function (): void {
    [$user, $product, $big, $bar] = alertFormProduct(sized: false);
    $product->forceFill(['unit_price_target' => '22.80', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $big->id])->save();
    $bar->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSet('unitPriceTargetUnit', 'g')
        ->assertSet('unitPriceTarget', null)
        ->assertSeeHtml('data-test="suspended-unit-target"')
        ->assertSee('stays until that shop says how much is in its pack')
        // Saving something else leaves it alone.
        ->set('title', 'Renamed')
        ->call('save');

    expect($product->refresh()->unit_price_target_unit)->toBe('pack');
});

it('lets the wizard save a price for one shop\'s pack', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
        ->test(AddProductWizard::class)
        ->assertSeeHtml('data-test="pack-target"')
        ->assertDontSeeHtml('data-test="other-alerts"')
        ->set('chosenPackShopId', $big->id)
        ->set('unitPriceTarget', '22.80')
        ->call('saveAlerts')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($big->id);
});

it('gives the product a price per kilo when the owner states the size of the pack they buy', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('How much is in this pack?')
        ->set('packSizeInput', '660 g')
        ->call('saveOwnerPackSize', $big->id)
        ->assertHasNoErrors()
        ->assertSet('unitPriceTargetUnit', 'g')
        ->assertSeeHtml('data-test="unit-target"')
        // The size stays changeable and removable once the product compares per kilo.
        ->assertSeeHtml('data-test="owner-pack-size-change"')
        ->assertSeeHtml('data-test="owner-pack-size-remove"');

    expect($big->refresh()->owner_pack_quantity)->toBe('660.00')
        ->and($product->refresh()->best_value_shop_id)->toBe($big->id);
});

it('refuses a word for the pack instead of what is in it', function (string $input): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('packSizeInput', $input)
        ->call('saveOwnerPackSize', $big->id)
        ->assertHasErrors('packSizeInput');

    expect($big->refresh()->owner_pack_quantity)->toBeNull();
})->with(['a box', '1 pack']);

it('removes the size the owner stated and goes back to a price for the pack', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('packSizeInput', '660 g')
        ->call('saveOwnerPackSize', $big->id)
        ->call('removeOwnerPackSize', $big->id)
        ->assertSet('unitPriceTargetUnit', 'pack')
        ->assertSeeHtml('data-test="pack-target"');

    expect($big->refresh()->owner_pack_quantity)->toBeNull();
});

it('names the size the shop states beside the one the owner stated', function (): void {
    [, $product, $big] = alertFormProduct(sized: false);
    $big->forceFill(['owner_pack_quantity' => '660.00', 'owner_pack_unit' => 'g', 'pack_quantity' => '600.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $packs = new UnitTargetGuide($product->refresh())->packs();

    expect($packs[0]['ownerSize'])->toBe('660 g')
        ->and($packs[0]['shopSays'])->toBe('600 g');
});

it('drops the size error once a size saves', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('packSizeInput', 'a box')
        ->call('saveOwnerPackSize', $big->id)
        ->assertHasErrors('packSizeInput')
        ->set('packSizeInput', '660 g')
        ->call('saveOwnerPackSize', $big->id)
        ->assertHasNoErrors();
});

it('loads the target again when a size turns a pack target into a price per kilo', function (): void {
    [$user, $product, $big, $bar] = alertFormProduct(sized: false);
    // The bar has a size, so the product compares per kilo; the 12-pack does not.
    $bar->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g'])->save();
    $product->forceFill(['unit_price_target' => '22.80', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $big->id])->save();
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSet('unitPriceTarget', null)
        ->set('packSizeInput', '660 g')
        ->call('saveOwnerPackSize', $big->id)
        // €22.80 for 660 g.
        ->assertSet('unitPriceTarget', '34.5454')
        ->assertDontSeeHtml('data-test="suspended-unit-target"');
});

it('saves a title while the pinned shop of an untouched pack target has sold out', function (): void {
    [$user, $product, $big] = alertFormProduct(sized: false);
    $product->forceFill(['unit_price_target' => '22.80', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $big->id])->save();
    $this->actingAs($user);

    $form = livewire(EditProduct::class, ['product' => $product->refresh()]);
    $big->forceFill(['current_in_stock' => false])->save();

    $form->set('title', 'Renamed')->call('save')->assertHasNoErrors();

    expect($product->refresh()->title)->toBe('Renamed')
        ->and($product->unit_price_target_shop_id)->toBe($big->id);
});

it('takes an amount off the chosen pack, and a percentage off today\'s best', function (): void {
    [$user, $product, , $bar] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('chosenPackShopId', $bar->id)
        ->set('alertHelper', 'amount')
        ->set('alertHelperValue', '0.50')
        ->call('save')
        ->assertHasNoErrors();

    // €2.49 for the chosen 55 g.
    expect($product->refresh()->unit_price_target)->toBe('45.2727');

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->set('chosenPackShopId', $bar->id)
        ->set('alertHelper', 'percent')
        ->set('alertHelperValue', '10')
        ->call('save')
        ->assertHasNoErrors();

    // 10% under the best value, the 660 g at €21.00, whichever pack is chosen.
    expect($product->refresh()->unit_price_target)->toBe('28.6363');
});

it('refuses a percentage of a hundred or more', function (): void {
    [$user, $product] = alertFormProduct();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('alertHelper', 'percent')
        ->set('alertHelperValue', '100')
        ->call('save')
        ->assertHasErrors('alertHelperValue');
});

it('still offers to remove a size the owner stated in another unit than the product compares in', function (): void {
    [$user, $product, $big, $bar] = alertFormProduct();
    $odd = Shop::factory()->for($product)->create(['url' => 'https://spar.nl/p/1', 'currency' => 'EUR', 'current_price' => '3.00', 'owner_pack_quantity' => '100.00', 'owner_pack_unit' => 'ml']);
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSeeHtml('data-test="owner-pack-size-stranded"')
        ->assertSee('Your size at SPAR, 100 ml, is not in the unit this product compares in.')
        ->call('removeOwnerPackSize', $odd->id)
        ->assertDontSeeHtml('data-test="owner-pack-size-stranded"');

    expect($odd->refresh()->owner_pack_quantity)->toBeNull();
});

it('does not call a size in the comparison unit stranded when its shop is only sold out', function (): void {
    [$user, $product] = alertFormProduct();
    Shop::factory()->for($product)->create(['url' => 'https://spar.nl/p/1', 'currency' => 'EUR', 'current_price' => '3.00', 'current_in_stock' => false, 'owner_pack_quantity' => '100.00', 'owner_pack_unit' => 'g']);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertDontSeeHtml('data-test="owner-pack-size-stranded"');
});

it('saves a helper target after a stranded size is removed', function (): void {
    [$user, $product] = alertFormProduct();
    $odd = Shop::factory()->for($product)->create(['url' => 'https://spar.nl/p/1', 'currency' => 'EUR', 'current_price' => '3.00', 'owner_pack_quantity' => '100.00', 'owner_pack_unit' => 'ml']);
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->call('removeOwnerPackSize', $odd->id)
        ->assertSet('chosenPackShopId', null)
        ->set('alertHelper', 'percent')
        ->set('alertHelperValue', '10')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target)->toBe('28.6363');
});
