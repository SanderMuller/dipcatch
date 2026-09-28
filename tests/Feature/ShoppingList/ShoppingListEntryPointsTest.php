<?php declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Livewire\ShoppingList\HeaderMenu;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * @param  array<model-property<Product>, mixed>  $attributes
 */
function entryPointProduct(User $user, string $title, array $attributes = [], string $host = 'ah.nl'): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR', ...$attributes]);
    Shop::factory()->for($product)->create(['url' => 'https://' . $host . '/p/' . Str::slug($title), 'current_price' => '2.49', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('adds a product to the list from its page, and the button changes at once', function (): void {
    $user = User::factory()->create();
    $product = entryPointProduct($user, 'Coffee');

    $this->actingAs($user);

    $page = livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Add to shopping list')
        ->call('toggleShoppingList')
        ->assertDispatched('shopping-list-changed')
        ->assertSee('Remove from shopping list')
        ->assertDontSee('Add to shopping list');

    expect($product->refresh()->isOnShoppingList())->toBeTrue();

    $page->call('toggleShoppingList')->assertSee('Add to shopping list');

    expect($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('refuses to change the list for a product the signed-in account does not own', function (): void {
    $owner = User::factory()->create();
    $product = entryPointProduct($owner, 'Coffee');

    $this->actingAs($owner);
    $page = livewire(ProductShow::class, ['product' => $product]);

    $this->actingAs(User::factory()->create());
    $page->call('toggleShoppingList')->assertForbidden();

    expect($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('un-crosses a crossed-off product that is added again from its page', function (): void {
    $user = User::factory()->create();
    $product = entryPointProduct($user, 'Coffee');
    $product->addToShoppingList();
    $product->setCrossedOff(crossedOff: true);

    $this->actingAs($user);

    // On the list, so the first toggle takes it off; the second puts it back open.
    livewire(ProductShow::class, ['product' => $product])
        ->call('toggleShoppingList')
        ->call('toggleShoppingList');

    expect($product->refresh()->isOnShoppingList())->toBeTrue()
        ->and($product->isCrossedOff())->toBeFalse();
});

it('marks a listed product on its card, and no other', function (): void {
    $user = User::factory()->create();
    entryPointProduct($user, 'Listed coffee', ['listed_at' => now()]);
    entryPointProduct($user, 'Plain tea');

    $this->actingAs($user);

    $html = livewire(ProductList::class)->html();

    expect(substr_count($html, 'group/list'))->toBe(1)
        ->and($html)->toContain('aria-label="Remove Listed coffee from shopping list"')
        ->and($html)->toContain('aria-label="Add Plain tea to shopping list"');
});

it('shows the open count, the first items and the way to the list in the header', function (): void {
    $user = User::factory()->create();
    entryPointProduct($user, 'Coffee', ['listed_at' => now()]);
    Product::factory()->for($user)->listedAndCrossedOff()->create(['title' => 'Crossed tea']);

    $this->actingAs($user);

    livewire(HeaderMenu::class)
        ->assertSeeHtml('data-test="shopping-list-badge"')
        ->assertSeeInOrder(['Coffee', '€2.49', 'ah.nl'])
        ->assertSeeHtml('src="' . e(Product::query()->where('title', 'Coffee')->firstOrFail()->safeImageUrl()) . '"')
        ->assertDontSee('Crossed tea')
        ->assertSeeHtml('href="' . route('app.shopping-list') . '"')
        ->assertSeeHtml('wire:poll.60s');
});

it('says 9+ above nine items and how many more the dropdown leaves out', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        entryPointProduct($user, 'Product ' . $i, ['listed_at' => now()->subMinutes(20 - $i)]);
    }

    $this->actingAs($user);

    livewire(HeaderMenu::class)
        ->assertSee('9+')
        ->assertSee('and 2 more')
        ->assertSee('Product 8')
        ->assertDontSee('Product 9');
});

it('tells an empty list from one where everything is crossed off', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(HeaderMenu::class)
        ->assertSee('Nothing on your list yet.')
        ->assertDontSeeHtml('data-test="shopping-list-badge"');

    Product::factory()->for($user)->listedAndCrossedOff()->create(['title' => 'Crossed tea']);

    livewire(HeaderMenu::class)
        ->assertSee('Everything is crossed off.')
        ->assertDontSee('Nothing on your list yet.')
        ->assertDontSeeHtml('data-test="shopping-list-badge"');
});

it('shows no price in the header for a product no shop sells now', function (): void {
    $user = User::factory()->create();
    entryPointProduct($user, 'Sold out', ['listed_at' => now()])->shops()->update(['current_in_stock' => false]);

    $this->actingAs($user);

    livewire(HeaderMenu::class)
        ->assertSee('No shop sells this now')
        ->assertDontSee('€2.49');
});

it('refreshes the header when the list changes on the page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $menu = livewire(HeaderMenu::class)->assertSee('Nothing on your list yet.');
    entryPointProduct($user, 'Coffee', ['listed_at' => now()]);

    $menu->dispatch('shopping-list-changed')->assertSee('Coffee');
});

it('reads the header in the same number of queries however long the list is', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 8) as $i) {
        entryPointProduct($user, 'Product ' . $i, ['listed_at' => now()]);
    }

    DB::enableQueryLog();
    livewire(HeaderMenu::class);
    $eight = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"products"') || str_contains($query['query'], '"shops"')));

    foreach (range(9, 30) as $i) {
        entryPointProduct($user, 'Product ' . $i, ['listed_at' => now()]);
    }

    DB::flushQueryLog();
    livewire(HeaderMenu::class);
    $thirty = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"products"') || str_contains($query['query'], '"shops"')));

    expect($thirty)->toBe($eight)->toBe(4);
});

it('adds and removes a product from its card on the product list', function (): void {
    $user = User::factory()->create();
    $product = entryPointProduct($user, 'Coffee');

    $this->actingAs($user);

    $list = livewire(ProductList::class)
        ->assertSeeHtml('aria-label="Add Coffee to shopping list"')
        ->assertSeeHtml('window.flyToList(')
        ->call('toggleShoppingList', $product->id)
        ->assertDispatched('shopping-list-changed')
        ->assertSeeHtml('aria-label="Remove Coffee from shopping list"')
        ->assertDontSeeHtml('window.flyToList(');

    expect($product->refresh()->isOnShoppingList())->toBeTrue();

    $list->call('toggleShoppingList', $product->id);

    expect($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('answers 404 from the card for another account\'s product or a non-UUID id', function (string $id): void {
    $theirs = entryPointProduct(User::factory()->create(), 'Theirs');
    $this->actingAs(User::factory()->create());

    livewire(ProductList::class)
        ->call('toggleShoppingList', $id === 'theirs' ? $theirs->id : $id)
        ->assertNotFound();

    expect($theirs->refresh()->isOnShoppingList())->toBeFalse();
})->with(['theirs', "1' OR 1=1"]);

it('keeps the dashboard cards to a plain label, with no list button', function (): void {
    $user = User::factory()->create();
    entryPointProduct($user, 'Listed coffee', ['listed_at' => now()]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertDontSeeHtml('data-test="card-list-toggle"');
});
