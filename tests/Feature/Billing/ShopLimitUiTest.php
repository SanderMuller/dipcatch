<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\RelationManagers\ShopsRelationManager;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

function shopLimitOwner(int $shops): User
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    Shop::factory()->count($shops)->create(['product_id' => $product->id]);

    test()->actingAs($user);
    Filament::setCurrentPanel('app');

    return $user;
}

function shopLimitProduct(User $user): Product
{
    return Product::query()->where('user_id', $user->id)->firstOrFail();
}

it('shows how much of the shop limit is used before it is reached', function (): void {
    $product = shopLimitProduct(shopLimitOwner(2));

    livewire(ShopsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertSee('Add a shop')
        ->assertSee('2 of 4 shops');
});

it('takes the add button away at the limit rather than refusing on confirm', function (): void {
    $product = shopLimitProduct(shopLimitOwner(4));

    livewire(ShopsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertDontSee('Add a shop')
        ->assertSee('This product is at its shop limit')
        ->assertSee('Your plan compares up to 4 shops per product, and this one has 4.')
        // The promise the limit rests on, said where it matters.
        ->assertSee('All of them keep being checked')
        ->assertSee('Compare plans');
});

it('says so plainly on a product that already sits over the limit', function (): void {
    // Shops added before the plan existed are kept, so a free account can
    // legitimately hold more than the limit allows.
    $product = shopLimitProduct(shopLimitOwner(5));

    livewire(ShopsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertDontSee('Add a shop')
        ->assertSee('and this one has 5.');
});

it('keeps every existing shop listed and checkable over the limit', function (): void {
    $product = shopLimitProduct(shopLimitOwner(5));

    livewire(ShopsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertCanSeeTableRecords($product->shops()->get());

    expect($product->shops()->where('active', true)->count())->toBe(5);
});

it('gives a pro account the button with no count', function (): void {
    $user = shopLimitOwner(9);
    subscribeUser($user);

    livewire(ShopsRelationManager::class, ['ownerRecord' => shopLimitProduct($user), 'pageClass' => ViewProduct::class])
        ->assertSee('Add a shop')
        ->assertDontSee('of 4 shops');
});

it('replaces the create button with a way forward at the product limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(ListProducts::class)
        ->assertActionHidden('create')
        ->assertActionVisible('planLimit');
});

it('keeps the create button below the product limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(19)->create(['user_id' => $user->id]);

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(ListProducts::class)
        ->assertActionVisible('create')
        ->assertActionHidden('planLimit');
});
