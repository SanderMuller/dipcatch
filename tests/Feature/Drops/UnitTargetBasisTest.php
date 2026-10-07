<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Livewire\Products\AddProductWizard;
use App\Livewire\Products\EditProduct;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\ProductPresenter;
use App\Mcp\Tools\SetThresholdTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

/**
 * A product whose two shops both sell by weight: 200 g at €1.69 and 370 g at
 * €1.99, so it compares per kilo and the best value is €5.3784/kg.
 */
/**
 * @param  array<model-property<Product>, mixed>  $attributes
 */
function weighedProduct(array $attributes = []): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', ...$attributes]);

    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    return $product->refresh();
}

/** Both shops start counting pieces instead, which moves the product to per piece. */
function countInPieces(Product $product): Product
{
    $product->shops()->update(['pack_quantity' => '20.00', 'pack_unit' => 'piece']);

    return $product->refresh();
}

beforeEach(function (): void {
    Notification::fake();
});

it('stamps the first unit a product gets on a target saved without one, and keeps its latch', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_notified' => '5.3784']);

    $product->recomputeCheapestShop();

    expect($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_target_effective)->toBe('5.5000')
        ->and($product->unit_price_notified)->toBe('5.3784')
        ->and($product->unit_price_notified_unit)->toBe('g');
});

it('suspends a target whose unit the product no longer compares in, and wakes it when the unit returns', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    expect($product->isAtTarget())->toBeTrue();

    countInPieces($product)->recomputeCheapestShop();

    // €1.69 for 20 pieces is €0.0845 a piece, far under 5.50: read as a price
    // per piece the target would fire on every check.
    expect($product->isUnitTargetSuspended())->toBeTrue()
        ->and($product->isAtTarget())->toBeFalse()
        ->and($product->hasActiveTarget())->toBeFalse()
        ->and(Product::query()->atTarget()->whereKey($product->id)->exists())->toBeFalse();

    app(DetectUnitPriceTarget::class)($product);
    Notification::assertNothingSent();

    $product->shops()->update(['pack_quantity' => '370.00', 'pack_unit' => 'g']);
    $product->refresh()->recomputeCheapestShop();

    expect($product->isUnitTargetSuspended())->toBeFalse()
        ->and($product->effectiveUnitPriceTarget())->toBe('5.5000')
        ->and(Product::query()->atTarget()->whereKey($product->id)->exists())->toBeTrue();
});

it('agrees in SQL and in PHP on whether a product is at its target', function (string $target, bool $expected): void {
    $product = weighedProduct(['unit_price_target' => $target, 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();

    expect($product->isAtTarget())->toBe($expected)
        ->and(Product::query()->atTarget()->whereKey($product->id)->exists())->toBe($expected);
})->with([
    'met' => ['5.50', true],
    'not met' => ['5.00', false],
]);

it('treats a latch in another unit as no latch', function (): void {
    $product = weighedProduct([
        'unit_price_target' => '5.50',
        'unit_price_target_unit' => 'g',
        // Armed per piece, which converts to nothing on a product that no
        // shop states both ways.
        'unit_price_notified' => '0.0100',
        'unit_price_notified_unit' => 'piece',
    ]);
    $product->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertSentTo($product->user()->firstOrFail(), UnitPriceTargetNotification::class);
    expect($product->refresh()->unit_price_notified_unit)->toBe('g');
});

it('clears the latch when a target moves to another unit at the same number, not when it first gets one', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_notified' => '5.3784']);

    $product->forceFill(['unit_price_target_unit' => 'g'])->save();
    expect($product->refresh()->unit_price_notified)->toBe('5.3784');

    $product->forceFill(['unit_price_target_unit' => 'piece'])->save();
    expect($product->refresh()->unit_price_notified)->toBeNull();
});

it('records and sends the target in the unit it was compared in', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product);

    expect(TargetPriceEvent::query()->sole())
        ->target->toBe('5.5000')
        ->comparison_unit->toBe('g');

    Notification::assertSentTo($product->user()->firstOrFail(), UnitPriceTargetNotification::class, function (UnitPriceTargetNotification $notification) use ($product): bool {
        // Delivered after the product moved to pieces, it still states the
        // target the alert was decided on.
        countInPieces($product)->recomputeCheapestShop();

        return $notification->toDatabase($product->user()->firstOrFail())['unit_price_target'] === '5.5000';
    });
});

it('leaves the target and its unit alone when the form is saved with the field untouched', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    $component = livewire(EditProduct::class, ['product' => $product])
        ->assertSet('unitPriceTarget', '5.5')
        ->set('title', 'Renamed');

    // Moved underneath the form: a save that never touched the target must
    // not write it back over this.
    Product::query()->whereKey($product->id)->update(['unit_price_target_unit' => 'piece']);

    $component->call('save')->assertHasNoErrors();

    expect($product->refresh())
        ->title->toBe('Renamed')
        ->unit_price_target->toBe('5.5000')
        ->unit_price_target_unit->toBe('piece');
});

it('stores a changed target in the unit the form showed', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    livewire(EditProduct::class, ['product' => $product])
        ->set('unitPriceTarget', '5.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh())
        ->unit_price_target->toBe('5.0000')
        ->unit_price_target_unit->toBe('g')
        ->unit_price_target_effective->toBe('5.0000');
});

it('reloads the target field when the product moves to another unit while the form is open', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    $component = livewire(EditProduct::class, ['product' => $product])
        ->assertSet('unitPriceTargetUnit', 'g')
        ->set('unitPriceTarget', '4.00');

    countInPieces($product)->recomputeCheapestShop();

    // The next render finds the product per piece, where the target does not
    // convert: the field reloads empty in the new unit rather than storing
    // 4.00 per kilo as 4.00 per piece.
    $component->call('$refresh')
        ->assertSet('unitPriceTargetUnit', 'piece')
        ->assertSet('unitPriceTarget', null)
        ->assertSee('Your target of', escape: false);
});

it('removes a suspended target', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    countInPieces($product)->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('Remove target')
        ->call('removeUnitTarget')
        // The notice stays, so focus stays on its button, and says what saving does.
        ->assertSee('will be removed when you save')
        ->assertSee('Keep target')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh())
        ->unit_price_target->toBeNull()
        ->unit_price_target_unit->toBeNull();
});

it('recomputes when the currency changes, since it decides which shops compare', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    expect($product->best_value_shop_id)->not->toBeNull();
    $this->actingAs($product->user()->firstOrFail());

    livewire(EditProduct::class, ['product' => $product])
        ->set('currency', 'SEK')
        ->call('save')
        ->assertHasNoErrors();

    // No shop compares in SEK, so the per-kilo target has nothing to be read against.
    expect($product->refresh()->best_value_shop_id)->toBeNull()
        ->and($product->isUnitTargetSuspended())->toBeTrue();
});

it('stamps the unit an MCP client sets a target in', function (): void {
    $product = weighedProduct();
    $product->recomputeCheapestShop();

    DipCatchServer::actingAs($product->user()->firstOrFail())
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 5.25])
        ->assertOk();

    expect($product->refresh())
        ->unit_price_target_unit->toBe('g')
        ->unit_price_target_effective->toBe('5.2500');
});

it('names a suspended target to an MCP client', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    countInPieces($product)->recomputeCheapestShop();

    $summary = app(ProductPresenter::class)->summary($product->load('shops'));

    expect($summary)
        ->unit_price_target->toBe('5.5000')
        ->unit_price_target_unit->toBe('g')
        ->unit_price_target_effective->toBeNull()
        ->unit_price_target_suspended->toBeTrue()
        ->comparison_unit->toBe('piece');
});

it('stamps every target and latch without a unit with today\'s unit, keeping the latch, and only once', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_notified' => '5.3784']);

    $this->artisan('dipcatch:stamp-target-units')->expectsOutput('Stamped 1 target(s).')->assertSuccessful();
    $this->artisan('dipcatch:stamp-target-units')->expectsOutput('Stamped 0 target(s).')->assertSuccessful();

    expect($product->refresh())
        ->unit_price_target_unit->toBe('g')
        ->unit_price_target_effective->toBe('5.5000')
        ->unit_price_notified->toBe('5.3784')
        ->unit_price_notified_unit->toBe('g');
});

it('keeps a suspended target the owner first chose to remove and then kept', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    countInPieces($product)->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    livewire(EditProduct::class, ['product' => $product])
        ->call('removeUnitTarget')
        ->call('keepUnitTarget')
        ->assertSee('Remove target')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_target)->toBe('5.5000');
});

it('stores a changed target in the unit the form showed, even when a check moved the product before the save', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    $component = livewire(EditProduct::class, ['product' => $product])->set('unitPriceTarget', '5.00');

    countInPieces($product)->recomputeCheapestShop();

    $component->call('save')->assertHasNoErrors();

    expect($product->refresh())
        ->unit_price_target->toBe('5.0000')
        ->unit_price_target_unit->toBe('g')
        ->and($product->isUnitTargetSuspended())->toBeTrue();
});

it('reloads the wizard\'s target field when shops added in it move the product to another unit', function (): void {
    $product = weighedProduct(['unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    $component = livewire(AddProductWizard::class, ['productId' => (string) $product->id, 'step' => 3])
        ->assertSet('unitPriceTargetUnit', 'g');

    countInPieces($product)->recomputeCheapestShop();

    $component->call('$refresh')
        ->assertSet('unitPriceTargetUnit', 'piece')
        ->assertSet('unitPriceTarget', null);
});

it('switches to a price alert in the unit the product compares in when it moved while the form was open', function (): void {
    $product = weighedProduct(['drop_threshold_pct' => '15.00']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    $component = livewire(EditProduct::class, ['product' => $product])->assertSet('unitPriceTargetUnit', 'g');

    countInPieces($product)->recomputeCheapestShop();

    $component->call('switchToPriceAlert')->assertSet('unitPriceTargetUnit', 'piece');

    // Whatever the switch filled in, the next render keeps it.
    $target = $component->get('unitPriceTarget');
    $component->call('$refresh')->assertSet('unitPriceTarget', $target);
});
