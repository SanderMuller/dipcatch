<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('saving an edit redirects to the product view page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProductResource::getUrl('view', ['record' => $product]));

    expect($product->refresh()->title)->toBe('Renamed');
});
