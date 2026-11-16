<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

beforeEach(function (): void {
    seedChains();
});

function viewPageProduct(): Product
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://kaasshop.test/p/1',
        'pack_quantity' => '150.00',
        'pack_unit' => 'g',
    ]);

    test()->actingAs($user);

    return $product->refresh();
}

test('the product view page shows the suggestions panel when the dataset matches', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = viewPageProduct();

    mountShopsRelationManager($product)
        ->assertSeeLivewire('suggestions.shop-suggestions')
        ->assertSee('SPAR');
});
