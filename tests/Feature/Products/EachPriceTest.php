<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\AlertRules;
use App\Support\AltPackSize;
use App\Support\HeadlinePrice;
use App\Support\PackLine;
use App\Support\PackSize;

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
    // per item, while the 1400 ml set stays the best value per litre.
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
