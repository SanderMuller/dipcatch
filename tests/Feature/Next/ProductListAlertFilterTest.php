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
