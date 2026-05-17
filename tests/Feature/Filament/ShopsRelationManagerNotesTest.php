<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\RelationManagers\ShopsRelationManager;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

test('edit_notes action saves a note on the shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => null]);

    $this->actingAs($user);

    livewire(ShopsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])
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

    livewire(ShopsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])
        ->mountAction(TestAction::make('edit_notes')->table($shop))
        ->assertActionDataSet(['notes' => 'existing note']);
});

test('edit_notes with an empty string clears the note back to null', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['notes' => 'temporary']);

    $this->actingAs($user);

    livewire(ShopsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])
        ->callAction(TestAction::make('edit_notes')->table($shop), [
            'notes' => '   ',
        ])
        ->assertHasNoActionErrors();

    expect($shop->fresh()->notes)->toBeNull();
});
