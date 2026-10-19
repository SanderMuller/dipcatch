<?php declare(strict_types=1);

use App\Actions\Shops\ShopDraft;
use App\Charts\PriceHistorySeries;
use App\Enums\ProductCategory;
use App\Jobs\CheckShopPrice;
use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductShow;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\AlertRules;
use App\Support\AltPackSize;
use App\Support\HeadlinePrice;
use App\Support\ItemPricing;
use App\Support\PackLine;
use App\Support\PackSize;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

/**
 * The Xenos glasses as they were tracked on 2026-10-19: one 350 ml glass, and
 * a set of four whose title counts the glasses.
 */
function glassesField(?User $user = null, ?ProductCategory $category = null): Product
{
    $product = Product::factory()->for($user ?? User::factory()->create())->create(['currency' => 'EUR', 'title' => 'Dubbelwandige glazen', 'category' => $category]);

    eachShop($product, 'xenos.nl', '5.99', '350.00', 'ml');
    eachShop($product, 'xenos.nl', '22.99', '1400.00', 'ml', '4.00', 'piece');

    return $product->refresh();
}

function eachShop(Product $product, string $host, string $price, string $quantity, string $unit, ?string $altQuantity = null, ?string $altUnit = null): void
{
    Shop::factory()->for($product)->create(['url' => "https://{$host}/p/" . fake()->uuid()])->forceFill([
        'currency' => 'EUR',
        'current_price' => $price,
        'current_in_stock' => true,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
        'alt_pack_quantity' => $altQuantity,
        'alt_pack_unit' => $altUnit,
    ])->save();
}

/** @return list<string> */
function packLines(Product $product): array
{
    $packs = $product->comparablePacks();

    return $product->shops->sortBy('current_price')->map(fn ($shop): string => PackLine::of($shop, $packs)->text())->values()->all();
}

test('a set title gives the pack its count of items', function (): void {
    $primary = PackSize::of(1400.0, 'ml');
    assert($primary instanceof PackSize);

    $alt = AltPackSize::first([], authoritative: false, primary: $primary, title: 'Dubbelwandige glazen - 350 ml - set van 4');

    expect($alt?->quantity)->toBe(4.0)
        ->and($alt?->unit)->toBe('piece');
});

test('a set of the same item as a single one shows its price per item', function (): void {
    expect(packLines(glassesField()))->toBe([
        '€5.99 for 350 ml',
        '€22.99 for 1.4 L · €5.75 each',
    ]);
});

test('the product page shows the price per item beside the pack price', function (): void {
    $user = User::factory()->create();
    $product = glassesField($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('€5.75 each')
        ->assertDontSee('€5.99 each');
});

test('no price per item when every shop sells the same count', function (): void {
    $product = Product::factory()->for(User::factory()->create())->create(['currency' => 'EUR']);
    eachShop($product, 'xenos.nl', '22.99', '1400.00', 'ml', '4.00', 'piece');
    eachShop($product, 'action.com', '19.99', '1400.00', 'ml');

    expect(packLines($product->refresh()))->each->not->toContain('each');
});

test('no price per item anywhere once one pack is not a whole number of the item', function (): void {
    $product = glassesField();
    eachShop($product, 'action.com', '9.99', '500.00', 'ml');

    expect(packLines($product->refresh()))->each->not->toContain('each');
});

test('no price per item while the product compares per piece', function (): void {
    $product = Product::factory()->for(User::factory()->create())->create(['currency' => 'EUR']);
    eachShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    eachShop($product, 'ah.nl', '6.59', '30.00', 'piece', '840.00', 'g');

    expect(packLines($product->refresh()))->each->not->toContain('each');
});

test('kitchenware leads with the price per item, and keeps the price per litre beside it', function (): void {
    $product = glassesField(category: ProductCategory::Kitchen);

    $headline = HeadlinePrice::of($product);
    $single = $product->shops->firstWhere('current_price', '5.99');
    assert($single instanceof Shop);

    expect($headline->text())->toBe('€5.75 each')
        // The shop rows on a product card, in the same terms as the headline.
        ->and($headline->figureFor($single, (string) $headline->packs->unitPriceOf($single)))->toBe('€5.99 each')
        ->and(packLines($product))->toBe([
            '€5.99 for 350 ml · €17.11 /l',
            '€22.99 for 4 × 350 ml · €16.42 /l',
        ]);
});

test('the product page reads per item for kitchenware', function (): void {
    $user = User::factory()->create();
    $product = glassesField($user, ProductCategory::Kitchen);
    $product->forceFill(['unit_price_target' => '52.5500', 'unit_price_target_unit' => 'ml'])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Sorted by price per item.')
        ->assertSeeInOrder(['Price per item', '4 × 350 ml', '€5.75 each', '€22.99', '€16.42 /l', '350 ml', '€5.99 each', '€17.11 /l'])
        ->assertSee('That is 4% more per item than the best value.')
        ->assertSee('€18.39 each')
        ->assertSee('€52.55 /l');

    expect(AlertRules::of($product->refresh())[0])->toMatchArray(['value' => '€18.39 each', 'pack' => '€52.55 /l']);
});

test('food keeps leading with the price per unit', function (): void {
    $product = glassesField(category: ProductCategory::SoftDrinks);

    expect(HeadlinePrice::of($product)->text())->toBe('€16.42 /l');
});

test('the price per item does not lead when it would rank the shops differently', function (): void {
    // 1380 ml is within 2% of four 350 ml glasses: €5.70 each is the lowest
    // per item, while the 1400 ml set stays the best value per litre. Not
    // exact, so the price per item stays beside the price per litre.
    $product = glassesField(category: ProductCategory::Kitchen);
    eachShop($product, 'action.com', '22.80', '1380.00', 'ml');

    $packs = $product->refresh()->comparablePacks();

    expect($packs->leadsWithItems())->toBeFalse()
        ->and($packs->items())->not->toBeNull();
});

test('a pack further than 2% from a whole number of items counts no items at all', function (): void {
    $product = glassesField(category: ProductCategory::Kitchen);
    eachShop($product, 'action.com', '21.99', '1350.00', 'ml');

    expect($product->refresh()->comparablePacks()->items())->toBeNull();
});

test('the chart and the edit form read per item for kitchenware', function (): void {
    $user = User::factory()->create();
    $product = glassesField($user, ProductCategory::Kitchen);
    $product->recomputeCheapestShop();

    $chart = new PriceHistorySeries($product->refresh(), '90')->fluxChart();

    expect($chart['unit'])->toBe(ItemPricing::UNIT)
        ->and(round($chart['latest']['unit']['value'] ?? 0.0, 2))->toBe(5.75);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('Any shop, any pack size, at the same price per item.')
        ->assertSee('Now €5.75 each at xenos.nl.')
        ->assertSee('4 × 350 ml');
});

test('an alert on kitchenware reads per item, as the page did when it fired', function (): void {
    $user = User::factory()->create();
    $product = glassesField($user, ProductCategory::Kitchen);
    $set = $product->shops->firstWhere('current_price', '22.99');
    assert($set instanceof Shop);

    $notification = new UnitPriceTargetNotification($product, $set, '16.4214', '52.5500');

    expect($notification->toDatabase($user)['item_size'])->toBe(350.0)
        ->and($notification->toWebPush($user)->toArray()['body'])->toStartWith('Dubbelwandige glazen is €5.75 each (€22.99 for 1.4 L)');
});

test('the assistant gets the price per item the app leads with', function (): void {
    $product = glassesField(category: ProductCategory::Kitchen);

    $summary = app(ProductPresenter::class)->summary($product->load('shops'));

    expect($summary)->toMatchArray(['headline_price' => '5.7475', 'headline_price_basis' => 'item', 'item_size' => 350.0]);
});

test('a price check reads a count after the size as items for kitchenware only', function (?ProductCategory $category, string $expected): void {
    Http::preventStrayRequests();
    Http::fake(['https://glasses.test/*' => Http::response(jsonLdPage('22.99', 'EUR', 'Glasses, 350 ml, 4 pcs'))]);
    $product = Product::factory()->for(User::factory()->create())->create(['currency' => 'EUR', 'category' => $category]);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://glasses.test/p/1']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->pack_quantity)->toBe($expected);
})->with([
    'kitchenware' => [ProductCategory::Kitchen, '1400.00'],
    'a drink' => [ProductCategory::SoftDrinks, '350.00'],
    'no category' => [null, '350.00'],
]);

test('the switch from a drop alert names its price per item for kitchenware', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = glassesField($user, ProductCategory::Kitchen);
    $product->forceFill(['drop_threshold_pct' => '10.00', 'drop_threshold_abs' => null, 'unit_price_target' => null])->save();
    $product->recomputeCheapestShop();

    $this->actingAs($user);

    // €16.4214 a litre, 10% off, is €14.7793 a litre: €5.17 for a 350 ml glass.
    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSeeHtml('data-test="price-alert-switch"')
        ->assertSee('at €5.17 per item or less')
        ->assertDontSee('€14.7');
});

test('the price per item does not lead while a pack is only near a whole number of items', function (): void {
    // 1390 ml is 0.7% off four 350 ml glasses: counted, but converting the
    // alert by 350 ml would not give the price per item its row shows.
    $product = glassesField(category: ProductCategory::Kitchen);
    eachShop($product, 'action.com', '23.50', '1390.00', 'ml');

    $packs = $product->refresh()->comparablePacks();

    expect($packs->items())->not->toBeNull()
        ->and($packs->leadsWithItems())->toBeFalse();
});

test('an alert event keeps the size of one item, for the digest', function (): void {
    $product = glassesField(category: ProductCategory::Kitchen);
    $set = $product->shops->firstWhere('current_price', '22.99');
    assert($set instanceof Shop);

    $event = TargetPriceEvent::record($product, $set, '52.5500', '22.99', '16.4214');

    expect($event->refresh()->item_size)->toBe(350.0);
});

test('a draft for a shop on kitchenware reads a count after the size as items', function (bool $countsItems, float $expected): void {
    $draft = ShopDraft::fromSnapshot(
        snapshot: ['title' => 'Glasses, 350 ml, 4 pcs', 'price' => '22.99', 'currency' => 'EUR'],
        url: 'https://glasses.test/p/1',
        adapterKey: 'jsonld',
        countsItems: $countsItems,
    );

    expect($draft->packSize?->quantity)->toBe($expected);
})->with([
    'kitchenware' => [true, 1400.0],
    'anything else' => [false, 350.0],
]);

test('the edit form reads per unit when no pack choice converts what is typed', function (): void {
    $user = User::factory()->create();
    $product = glassesField($user, ProductCategory::Kitchen);
    $product->shops()->update(['current_in_stock' => false]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])->assertDontSee('per item');
});
