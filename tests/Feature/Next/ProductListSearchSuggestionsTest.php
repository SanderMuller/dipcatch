<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\FilterSuggestions;

use function Pest\Livewire\livewire;

/**
 * Coffee at jumbo.com, dish soap at ah.nl.
 */
function searchSuggestionAccount(): User
{
    $user = User::factory()->create();
    $coffee = Product::factory()->for($user)->categorised(ProductCategory::CoffeeTea)->create(['title' => 'Aroma Rood']);
    $soap = Product::factory()->for($user)->categorised(ProductCategory::Cleaning)->create(['title' => 'Dish soap']);
    Shop::factory()->for($coffee)->create(['url' => 'https://www.jumbo.com/p/coffee']);
    Shop::factory()->for($soap)->create(['url' => 'https://www.ah.nl/p/soap']);

    return $user;
}

it('offers the shop a search names, and filters by it in place of the search', function (): void {
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('search', 'jumbo')
        ->assertSee('No product matches that search.')
        ->assertSeeHtml("filterByShop('jumbo.com')")
        ->call('filterByShop', 'jumbo.com')
        ->assertSet('shop', 'jumbo.com')
        ->assertSet('search', '')
        ->assertSee('Aroma Rood')
        ->assertDontSee('Dish soap');
});

it('offers a shop by its name as people say it', function (): void {
    config()->set('site.shop_names', ['ah.nl' => 'Albert Heijn']);
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('search', 'albert')
        ->assertSeeHtml("filterByShop('ah.nl')")
        ->assertDontSeeHtml("filterByShop('jumbo.com')");
});

it('offers a department over its own categories, and a category the account uses', function (): void {
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('search', 'food')
        ->assertSeeHtml("filterByCategory('food')")
        ->assertDontSeeHtml("filterByCategory('food.coffee_tea')")
        ->set('search', 'coffee')
        ->assertSeeHtml("filterByCategory('food.coffee_tea')")
        ->call('filterByCategory', 'food.coffee_tea')
        ->assertSet('category', 'food.coffee_tea')
        ->assertSet('search', '')
        ->assertSee('Aroma Rood')
        ->assertDontSee('Dish soap');
});

it('offers no shop or category the account follows nothing in, and nothing for one letter', function (): void {
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('search', 'dairy')
        ->assertDontSeeHtml('data-test="search-suggestions"')
        ->set('search', 'j')
        ->assertDontSeeHtml('data-test="search-suggestions"')
        ->set('search', 'jumbo')
        ->set('shop', 'jumbo.com')
        ->assertDontSeeHtml('data-test="search-suggestions"');
});

it('ignores a category key that is not one', function (): void {
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('search', 'coffee')
        ->call('filterByCategory', 'nonsense')
        ->assertSet('category', '')
        ->assertSet('search', 'coffee');
});

it('does not offer the category already chosen', function (): void {
    $this->actingAs(searchSuggestionAccount());

    livewire(ProductList::class)
        ->set('category', 'food.coffee_tea')
        ->set('search', 'coffee')
        ->assertDontSeeHtml("filterByCategory('food.coffee_tea')");
});

it('offers shops before categories, and at most three', function (): void {
    $groups = ['food' => [ProductCategory::CoffeeTea]];

    expect(FilterSuggestions::for('co', ['coolblue.nl', 'coop.nl', 'cool.com', 'costco.nl'], $groups, except: []))
        ->toHaveCount(3)
        ->each(fn ($suggestion) => $suggestion->kind->toBe('shop'));

    expect(array_column(FilterSuggestions::for('co', ['coop.nl'], $groups, except: []), 'kind'))->toBe(['shop', 'category']);
});

it('writes a shop host into the chip as a script string, so a quote in it stays text', function (): void {
    $user = User::factory()->create();
    $shop = Shop::factory()->for(Product::factory()->for($user)->create())->create(['url' => 'https://www.jumbo.com/p/1']);
    $shop->forceFill(['host' => "jumbo.com')+alert(1)+('"])->saveQuietly();
    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('search', 'jumbo')
        ->assertSeeHtml('filterByShop(\'jumbo.com\u0027)+alert(1)+(\u0027\')')
        ->assertDontSeeHtml("filterByShop('jumbo.com')+alert(1)");
});
