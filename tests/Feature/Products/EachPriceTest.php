<?php declare(strict_types=1);

use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\AltPackSize;
use App\Support\PackLine;
use App\Support\PackSize;

use function Pest\Livewire\livewire;

/**
 * The Xenos glasses as they were tracked on 2026-10-19: one 350 ml glass, and
 * a set of four whose title counts the glasses.
 */
function glassesField(?User $user = null): Product
{
    $product = Product::factory()->for($user ?? User::factory()->create())->create(['currency' => 'EUR', 'title' => 'Dubbelwandige glazen']);

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
