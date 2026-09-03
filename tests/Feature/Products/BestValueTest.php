<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

test('a cheapest shop with no pack size shows no unit price beside it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $sizeless = Shop::factory()->for($product)->create([
        'url' => 'https://dataset.test/p/1', 'currency' => 'EUR', 'current_price' => '0.99',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p2', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $sizeless->id, 'cheapest_price' => '0.99'])->save();

    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->refresh()->getKey()])
        ->assertSeeText('€0.99')
        // The best value still stands on its own.
        ->assertSeeText('€5.38 /kg');
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
        // Both stated per kilo, so the gap between them can be read off.
        ->assertSeeText('€8.45 /kg')
        ->assertSeeText('Best value')
        ->assertSeeText('€5.38 /kg')
        ->assertSeeText('lidl.nl');
});

test('the products list shows the best value beside the cheapest price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => "Lay's Naturel"]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi9/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p9', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(ListProducts::class)
        ->assertSeeText('€1.69')
        ->assertSeeText('€8.45 /kg')
        ->assertSeeText('€5.38 /kg')
        // Named, because the best value is usually not the cheapest shop.
        ->assertSeeText('lidl.nl');
});

test('the list reads every shop once, not once per product row', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 8) as $i) {
        $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
        Shop::factory()->for($product)->create([
            'url' => "https://shop{$i}.test/p/1", 'currency' => 'EUR', 'current_price' => '1.99',
            'pack_quantity' => '370.00', 'pack_unit' => 'g',
        ]);
    }

    $this->actingAs($user);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    livewire(ListProducts::class)->assertSeeText('€5.38 /kg');

    // Eager loading makes this flat: eight products cost the same three
    // queries as one. Reading shops per row would cost eight more.
    expect($queries)->toBeLessThanOrEqual(4);
});
