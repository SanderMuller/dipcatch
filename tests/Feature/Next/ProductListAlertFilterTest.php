<?php declare(strict_types=1);

use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

it('lists a product at the alert price under "Only discounts"', function (): void {
    $user = User::factory()->create();

    foreach (['At the alert price' => '2.50', 'Above the alert price' => '1.50'] as $title => $target) {
        $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR', 'target_price' => $target]);
        $shop = Shop::factory()->for($product)->create(['current_price' => '2.00']);
        $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '2.00'])->save();
    }

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('discounted', true)
        ->assertSee('At the alert price')
        ->assertDontSee('Above the alert price');
});

it('lists a product at the alert price under "Only discounts" at the shop with that price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'At the alert price', 'currency' => 'EUR', 'target_price' => '2.50']);
    $cheapest = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '2.00']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '3.00']);
    $product->forceFill(['cheapest_shop_id' => $cheapest->id, 'cheapest_price' => '2.00'])->save();

    $this->actingAs($user);

    livewire(ProductList::class)->set('discounted', true)->set('shop', 'ah.nl')->assertSee('At the alert price');
    livewire(ProductList::class)->set('discounted', true)->set('shop', 'jumbo.com')->assertDontSee('At the alert price');
});
