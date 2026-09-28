<?php declare(strict_types=1);

use App\Livewire\ShoppingList\ShoppingListPage;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * A listed product with one shop, sold at `$price`.
 */
function shoppingPageProduct(User $user, string $title, string $host = 'ah.nl', string $price = '1.00'): Product
{
    $product = Product::factory()->for($user)->listed()->create(['title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://' . $host . '/p/' . Str::slug($title), 'current_price' => $price, 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('redirects a guest to the login page', function (): void {
    $this->get(route('app.shopping-list'))->assertRedirect(route('login'));
});

it('shows the list grouped by shop, with the open count and the price', function (): void {
    $user = User::factory()->create();
    shoppingPageProduct($user, 'Coffee', 'ah.nl', '3.49');
    shoppingPageProduct($user, 'Milk', 'jumbo.com', '1.19');

    $this->actingAs($user)
        ->get(route('app.shopping-list'))
        ->assertOk()
        ->assertSeeText('2 to buy')
        ->assertSeeInOrder(['ah.nl', 'Coffee', '€3.49'])
        ->assertSeeInOrder(['jumbo.com', 'Milk', '€1.19']);
});

it('says so when the list is empty', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ShoppingListPage::class)
        ->assertSeeHtml('data-test="shopping-list-empty"')
        ->assertSee('Your shopping list is empty.')
        ->assertDontSeeHtml('data-test="shopping-list-print"');
});

it('shows no price for a product no shop sells now', function (): void {
    $user = User::factory()->create();
    shoppingPageProduct($user, 'Sold out', 'ah.nl', '7.77')->shops()->update(['current_in_stock' => false]);

    $this->actingAs($user);

    livewire(ShoppingListPage::class)
        ->assertSee('No shop sells this now')
        ->assertSee('Sold out')
        ->assertDontSee('€7.77');
});

it('crosses an item off and back, and says the list changed', function (): void {
    $user = User::factory()->create();
    $product = shoppingPageProduct($user, 'Coffee');

    $this->actingAs($user);

    $page = livewire(ShoppingListPage::class)
        ->call('toggleCrossedOff', $product->id)
        ->assertDispatched('shopping-list-changed')
        ->assertSee('Clear crossed off (1)')
        ->assertSeeHtml('aria-label="Put Coffee back on the list"');

    expect($product->refresh()->isCrossedOff())->toBeTrue();

    $page->call('toggleCrossedOff', $product->id)->assertDontSeeHtml('data-test="shopping-list-clear"');

    expect($product->refresh()->isCrossedOff())->toBeFalse()
        ->and($product->isOnShoppingList())->toBeTrue();
});

it('removes an item from the list, and the product stays tracked', function (): void {
    $user = User::factory()->create();
    $product = shoppingPageProduct($user, 'Coffee');

    $this->actingAs($user);

    livewire(ShoppingListPage::class)
        ->assertSeeHtml('aria-label="Remove Coffee from shopping list"')
        ->call('remove', $product->id)
        ->assertDispatched('shopping-list-changed')
        ->assertSee('Your shopping list is empty.');

    expect($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('clears only the crossed-off items', function (): void {
    $user = User::factory()->create();
    $crossed = shoppingPageProduct($user, 'Bread');
    $crossed->setCrossedOff(crossedOff: true);
    $open = shoppingPageProduct($user, 'Butter');

    $this->actingAs($user);

    livewire(ShoppingListPage::class)
        ->call('clearCrossedOff')
        ->assertDispatched('shopping-list-changed')
        ->assertDontSee('Bread')
        ->assertSee('Butter');

    expect($crossed->refresh()->isOnShoppingList())->toBeFalse()
        ->and($open->refresh()->isOnShoppingList())->toBeTrue();
});

it('does nothing when clear is called with nothing crossed off', function (): void {
    $user = User::factory()->create();
    $open = shoppingPageProduct($user, 'Open');

    $this->actingAs($user);

    livewire(ShoppingListPage::class)
        ->assertDontSeeHtml('data-test="shopping-list-clear"')
        ->call('clearCrossedOff');

    expect($open->refresh()->isOnShoppingList())->toBeTrue();
});

it('answers 404 for another account\'s product and changes nothing', function (string $action): void {
    $user = User::factory()->create();
    $theirs = shoppingPageProduct(User::factory()->create(), 'Theirs');

    $this->actingAs($user);

    livewire(ShoppingListPage::class)->call($action, $theirs->id)->assertNotFound();

    expect($theirs->refresh()->isOnShoppingList())->toBeTrue()
        ->and($theirs->isCrossedOff())->toBeFalse();
})->with(['toggleCrossedOff', 'remove']);

it('answers 404 for an id that is not a UUID', function (string $action): void {
    $this->actingAs(User::factory()->create());

    livewire(ShoppingListPage::class)->call($action, "1' OR 1=1")->assertNotFound();
})->with(['toggleCrossedOff', 'remove']);

it('does not bring back an item another tab removed when it is crossed off', function (): void {
    $user = User::factory()->create();
    $product = shoppingPageProduct($user, 'Coffee');

    $this->actingAs($user);
    $page = livewire(ShoppingListPage::class);
    $product->removeFromShoppingList();

    $page->call('toggleCrossedOff', $product->id);

    expect($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('renders the page in the same number of queries however long the list is', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    shoppingPageProduct($user, 'First');

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(ShoppingListPage::class);

        return count(DB::getQueryLog());
    };

    $one = $count();

    foreach (range(1, 12) as $i) {
        shoppingPageProduct($user, 'Product ' . $i, $i % 2 === 0 ? 'ah.nl' : 'lidl.nl');
    }

    expect($count())->toBe($one);
});

it('regroups the list when a shop is skipped, and back when it is picked again', function (): void {
    $user = User::factory()->create();
    $coffee = shoppingPageProduct($user, 'Coffee', 'ah.nl', '1.00');
    Shop::factory()->for($coffee)->create(['url' => 'https://jumbo.com/p/coffee', 'current_price' => '1.50', 'currency' => 'EUR']);
    shoppingPageProduct($user, 'Milk', 'jumbo.com', '0.99');

    $this->actingAs($user);

    livewire(ShoppingListPage::class)
        ->assertSeeHtml('data-test="shopping-list-shops"')
        ->assertSeeInOrder(['ah.nl', 'Coffee', 'jumbo.com', 'Milk'])
        ->call('toggleShop', 'ah.nl')
        ->assertSet('skip', ['ah.nl'])
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSeeInOrder(['Shops you are going to', 'jumbo.com', 'Coffee', 'Milk'])
        ->assertSee('€1.50')
        ->call('toggleShop', 'ah.nl')
        ->assertSet('skip', []);
});

it('ignores a shop value that is not a host', function (mixed $host): void {
    $this->actingAs(User::factory()->create());

    livewire(ShoppingListPage::class)->call('toggleShop', $host)->assertSet('skip', []);
})->with([[''], [['ah.nl']], [str_repeat('a', 300)]]);

it('reads skipped shops from the URL', function (): void {
    $user = User::factory()->create();
    shoppingPageProduct($user, 'Tea', 'lidl.nl');

    $this->actingAs($user)
        ->get(route('app.shopping-list', ['skip' => ['lidl.nl']]))
        ->assertOk()
        ->assertSeeText('Only at shops you skip')
        ->assertSeeText('Sold at lidl.nl')
        ->assertSeeText('Go to lidl.nl too');
});

it('brings a skipped shop back from the item that needs it', function (): void {
    $user = User::factory()->create();
    shoppingPageProduct($user, 'Tea', 'lidl.nl');

    $this->actingAs($user);

    livewire(ShoppingListPage::class, ['skip' => ['lidl.nl']])
        ->assertSee('Only at shops you skip')
        ->call('toggleShop', 'lidl.nl')
        ->assertSet('skip', [])
        ->assertDontSee('Only at shops you skip');
});

it('counts on each shop button what is listed under that shop, not every item it sells', function (): void {
    $user = User::factory()->create();
    $coffee = shoppingPageProduct($user, 'Coffee', 'ah.nl', '1.00');
    Shop::factory()->for($coffee)->create(['url' => 'https://jumbo.com/p/coffee', 'current_price' => '1.50', 'currency' => 'EUR']);
    shoppingPageProduct($user, 'Milk', 'jumbo.com', '0.99');

    $this->actingAs($user);

    // jumbo.com sells both, but only Milk is listed under it.
    livewire(ShoppingListPage::class)->assertSeeHtml('title="1 to buy here"')->assertDontSeeHtml('title="2 to buy here"');
});
