<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

test('edit_notes action saves a note on the shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => null]);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->call('saveShopNotes', $shop->id, "ships only to NL\ncoupon CODE10")
        ->assertSet('shopMessage', 'Notes saved');

    expect($shop->fresh()->notes)->toBe("ships only to NL\ncoupon CODE10");
});

test('edit_notes pre-fills the existing value', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => 'existing note']);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSet('shopMessage', null);

    expect($shop->fresh()?->notes)->toBe('existing note');
});

test('cannot edit notes on a shop that belongs to a different user\'s product', function (): void {
    $owner = User::factory()->create();
    $ownerProduct = Product::factory()->for($owner)->create();

    $stranger = User::factory()->create();
    $strangerProduct = Product::factory()->for($stranger)->create();
    $strangerShop = Shop::factory()->for($strangerProduct)->create(['notes' => 'private']);

    $this->actingAs($owner);

    // Mount the relation manager scoped to $owner's product, then try to
    // forge a table action against $stranger's shop. Filament resolves
    // table-action records through the relation-scoped query, so the
    // stranger's shop isn't reachable from this surface and the action
    // must NOT mutate the note.
    // The page was rendered for the owner's product, but a Livewire call
    // carries whatever id the client sends — ShopPolicy is what refuses it.
    mountShopsRelationManager($ownerProduct)
        ->call('saveShopNotes', $strangerShop->id, 'hacked')
        ->assertForbidden();

    expect($strangerShop->fresh()->notes)->toBe('private');
});

test('edit_notes with an empty string clears the note back to null', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => 'temporary']);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->call('saveShopNotes', $shop->id, '   ')
        ->assertSet('shopMessage', 'Notes saved');

    expect($shop->fresh()->notes)->toBeNull();
});

test('the notes indicator shows only for a shop with non-empty notes', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'notes' => 'has a note']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'notes' => '']);
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1', 'notes' => null]);

    $this->actingAs($user);

    $html = mountShopsRelationManager($product)->html();

    expect(substr_count($html, 'data-slot="notes_indicator"'))->toBe(1);
});
