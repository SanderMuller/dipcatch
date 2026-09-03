<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

/**
 * Shops named by host. `Shop::booted()` derives the host from the URL, so
 * the URL is what decides which shop a test is naming.
 *
 * @param  array<string, array<string, mixed>>  $shops  host => attributes
 */
function productWithShops(array $shops): Product
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    foreach ($shops as $host => $attributes) {
        $shop = Shop::factory()->for($product)->create([
            'url' => 'https://' . $host . '/p/' . bin2hex(random_bytes(4)),
        ]);

        $shop->forceFill(['currency' => 'EUR', ...$attributes])->save();
    }

    return $product->refresh();
}

test('the best value is the lowest price per unit, not the lowest price', function (): void {
    // The Lay's case: a 370 g bag at 1.99 beats a 200 g bag at 1.69 per kilo.
    $product = productWithShops([
        'ah.nl' => ['current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl')
        ->and($product->bestValueShop()?->unitPrice())->toBe('5.38');
});

test('a shop with no pack size cannot be the best value', function (): void {
    $product = productWithShops([
        'boodschaapje.nl' => ['current_price' => '0.99'],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl');
});

test('units that cannot be compared are not compared', function (): void {
    // Two shops price per kilo, one per piece. EUR/kg and EUR/piece are not
    // the same measure, so the larger group decides.
    $product = productWithShops([
        'a.test' => ['current_price' => '1.00', 'pack_quantity' => '1.00', 'pack_unit' => 'piece'],
        'b.test' => ['current_price' => '2.00', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
        'c.test' => ['current_price' => '2.50', 'pack_quantity' => '400.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('c.test');
});

test('paused, dead and out-of-stock shops are left out', function (): void {
    $product = productWithShops([
        'cheap-but-paused.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'active' => false],
        'cheap-but-dead.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'health' => ShopHealth::Dead],
        'cheap-but-gone.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'current_in_stock' => false],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl');
});

test('a product whose shops state no size has no best value', function (): void {
    $product = productWithShops(['a.test' => ['current_price' => '1.00']]);

    expect($product->bestValueShop())->toBeNull();
});

test('the product page shows the best value beside the cheapest price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi1/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p1', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->refresh()->getKey()])
        ->assertSeeText('Cheapest now')
        ->assertSeeText('€1.69')
        ->assertSeeText('Best value')
        ->assertSeeText('€5.38 /kg')
        ->assertSeeText('lidl.nl');
});
