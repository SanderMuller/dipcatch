<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;

use function Pest\Livewire\livewire;

/**
 * @return array{0: User, 1: Product}
 */
function seedPricedProduct(): array
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'currency' => 'EUR',
        'current_price' => '1.69',
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '1.69',
    ])->save();

    return [$user, $product->refresh()];
}

test('the products table renders price and unit price symbol-first', function (): void {
    [$user] = seedPricedProduct();
    $this->actingAs($user);

    livewire(ListProducts::class)
        ->assertSeeText('€1.69')
        ->assertSeeText('€8.45 /kg')
        ->assertDontSeeText('EUR 1.69');
});

test('the product infolist renders "Cheapest now" symbol-first', function (): void {
    [$user, $product] = seedPricedProduct();
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertSeeText('€1.69')
        ->assertDontSeeText('EUR 1.69');
});

test('the shops table renders price and unit price symbol-first', function (): void {
    [$user, $product] = seedPricedProduct();
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('€1.69')
        ->assertSeeText('€8.45 /kg')
        ->assertDontSeeText('EUR 1.69');
});

test('the compact mobile price column carries the price and the unit price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'current_price' => '1.69',
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertTableColumnFormattedStateSet('price_compact', '€1.69', $shop)
        ->assertTableColumnExists('price_compact', fn (TextColumn $column): bool => $column->getDescriptionBelow() === '€8.45 /kg', $shop)
        ->sortTable('price_compact', 'desc')
        ->assertCanSeeTableRecords([$shop]);
});
