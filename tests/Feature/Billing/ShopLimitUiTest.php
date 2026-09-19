<?php declare(strict_types=1);

use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

function shopLimitOwner(int $shops): User
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    Shop::factory()->count($shops)->create(['product_id' => $product->id]);

    test()->actingAs($user);

    return $user;
}

function shopLimitProduct(User $user): Product
{
    return Product::query()->where('user_id', $user->id)->firstOrFail();
}

it('keeps the shop count for the add form rather than showing it beside the button', function (): void {
    $product = shopLimitProduct(shopLimitOwner(2));

    // Beside a closed button the count read as a tally of the suggestions
    // underneath it, so it is revealed with the form instead.
    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Add a shop')
        ->assertSee('2 of 4 shops on your plan')
        ->assertSeeHtml('x-show="addOpen"');
});

it('takes the add button away at the limit rather than refusing on confirm', function (): void {
    $product = shopLimitProduct(shopLimitOwner(4));

    livewire(ProductShow::class, ['product' => $product])
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

    livewire(ProductShow::class, ['product' => $product])
        ->assertDontSee('Add a shop')
        ->assertSee('and this one has 5.');
});

it('keeps every existing shop listed and checkable over the limit', function (): void {
    $product = shopLimitProduct(shopLimitOwner(5));

    // Over the limit, every existing shop stays listed and checked; only
    // adding another is blocked.
    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('This product is at its shop limit');

    expect($product->shops()->where('active', true)->count())->toBe(5);
});

it('gives a pro account the button with no count', function (): void {
    $user = shopLimitOwner(9);
    subscribeUser($user);

    livewire(ProductShow::class, ['product' => shopLimitProduct($user)])
        ->assertSee('Add a shop')
        ->assertDontSee('of 4 shops');
});

it('replaces the create button with a way forward at the product limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('You are following as many products as the free plan allows.');
});

it('keeps the create button below the product limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(19)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('Track a product')
        ->assertDontSee('You are following as many products as the free plan allows.');
});
