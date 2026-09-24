<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

/**
 * Coffee at ah.nl and jumbo.com, dish soap at ah.nl and action.com.
 *
 * @return array{0: User, 1: Product, 2: Product}
 */
function shopFilterAccount(): array
{
    $user = User::factory()->create();
    $coffee = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    $soap = Product::factory()->categorised(ProductCategory::Cleaning)->create(['user_id' => $user->id, 'title' => 'Dish soap']);

    Shop::factory()->for($coffee)->create(['url' => 'https://www.ah.nl/p/coffee']);
    Shop::factory()->for($coffee)->create(['url' => 'https://www.jumbo.com/p/coffee']);
    Shop::factory()->for($soap)->create(['url' => 'https://www.ah.nl/p/soap']);
    Shop::factory()->for($soap)->create(['url' => 'https://www.action.com/p/soap']);

    return [$user, $coffee, $soap];
}

it('filters the products to the ones tracked at a shop', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('shop', 'jumbo.com')
        ->assertSee('Aroma Rood')
        ->assertDontSee('Dish soap');
});

it('lists every shop the account tracks at, alphabetically', function (): void {
    [$user] = shopFilterAccount();
    Shop::factory()->create(['url' => 'https://www.plus.nl/p/not-mine']);

    $this->actingAs($user);

    $component = livewire(ProductList::class);

    expect($component->viewData('shopHosts'))->toBe(['action.com', 'ah.nl', 'jumbo.com'])
        // What a click sends: a Blade directive inside a component tag is not
        // compiled, so a literal one here would set nothing.
        ->and($component->html())->toContain('wire:click="$set(\'shop\', \'ah.nl\')"');
});

it('lists only the shops with a product in the category filter', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    expect(livewire(ProductList::class)->set('category', 'food')->viewData('shopHosts'))->toBe(['ah.nl', 'jumbo.com'])
        ->and(livewire(ProductList::class)->set('category', ProductCategory::Cleaning->value)->viewData('shopHosts'))->toBe(['action.com', 'ah.nl']);
});

it('leaves out a paused shop', function (): void {
    [$user, $coffee] = shopFilterAccount();
    Shop::factory()->for($coffee)->inactive()->create(['url' => 'https://www.lidl.nl/p/coffee']);

    $this->actingAs($user);

    expect(livewire(ProductList::class)->viewData('shopHosts'))->not->toContain('lidl.nl');
});

it('keeps the selected shop offered when the category leaves it out', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('shop', 'action.com')
        ->set('category', 'food')
        ->assertViewHas('shopHosts', ['ah.nl', 'jumbo.com', 'action.com'])
        ->assertSee('No product matches this filter.');
});
