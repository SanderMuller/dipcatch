<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\RelationManagers\ShopsRelationManager;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

/**
 * Mount the per-product Shops relation manager scoped to a given product.
 * Centralises the (ownerRecord + pageClass) wiring so tests don't repeat it.
 */
function mountShopsRelationManager(Product $product): Testable
{
    return livewire(ShopsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ]);
}

test('edit_notes action saves a note on the shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => null]);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->callAction(TestAction::make('edit_notes')->table($shop), [
            'notes' => "ships only to NL\ncoupon CODE10",
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($shop->fresh()->notes)->toBe("ships only to NL\ncoupon CODE10");
});

test('edit_notes pre-fills the existing value', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => 'existing note']);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->mountAction(TestAction::make('edit_notes')->table($shop))
        ->assertActionDataSet(['notes' => 'existing note']);
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
    expect(fn () => mountShopsRelationManager($ownerProduct)
        ->callAction(TestAction::make('edit_notes')->table($strangerShop), [
            'notes' => 'hacked',
        ]))->toThrow(Exception::class);

    expect($strangerShop->fresh()->notes)->toBe('private');
});

test('edit_notes with an empty string clears the note back to null', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => 'temporary']);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->callAction(TestAction::make('edit_notes')->table($shop), [
            'notes' => '   ',
        ])
        ->assertHasNoActionErrors();

    expect($shop->fresh()->notes)->toBeNull();
});

test('indicator column state is true only for shops with non-empty notes', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $withNotes = Shop::factory()->for($product)->create(['notes' => 'has a note']);
    $blankNotes = Shop::factory()->for($product)->create(['notes' => '']);
    $nullNotes = Shop::factory()->for($product)->create(['notes' => null]);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertTableColumnStateSet('notes_indicator', true, $withNotes)
        ->assertTableColumnStateSet('notes_indicator', false, $blankNotes)
        ->assertTableColumnStateSet('notes_indicator', false, $nullNotes);
});
