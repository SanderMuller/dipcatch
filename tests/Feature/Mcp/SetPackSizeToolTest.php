<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\SetPackSizeTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

it('saves how much is in the pack at one shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['currency' => 'EUR', 'current_price' => '21.00']);

    DipCatchServer::actingAs($user)
        ->tool(SetPackSizeTool::class, ['shop_id' => (string) $shop->id, 'pack_size' => '660 g'])
        ->assertOk()
        ->assertSee('"pack_size_provenance":"owner"');

    expect($shop->refresh()->owner_pack_quantity)->toBe('660.00')
        ->and($shop->owner_pack_unit)->toBe('g')
        ->and($product->refresh()->comparablePacks()->unit())->toBe('g');
});

it('refuses a word for the pack', function (): void {
    $user = User::factory()->create();
    $shop = Shop::factory()->for(Product::factory()->for($user))->create();

    DipCatchServer::actingAs($user)
        ->tool(SetPackSizeTool::class, ['shop_id' => (string) $shop->id, 'pack_size' => '1 pack'])
        ->assertHasErrors(['Write how much is in the pack']);

    expect($shop->refresh()->owner_pack_quantity)->toBeNull();
});

it('refuses a shop of another user', function (): void {
    $shop = Shop::factory()->create();

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(SetPackSizeTool::class, ['shop_id' => (string) $shop->id, 'pack_size' => '660 g'])
        ->assertHasErrors(['No such shop.']);

    expect($shop->refresh()->owner_pack_quantity)->toBeNull();
});
