<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

it('lists only the signed-in users products', function (): void {
    $mine = User::factory()->create();
    Product::factory()->create(['user_id' => $mine->id, 'title' => 'My coffee']);
    Product::factory()->create(['title' => 'Somebody elses coffee']);

    $this->actingAs($mine);

    livewire(ProductList::class)
        ->assertSee('My coffee')
        ->assertDontSee('Somebody elses coffee');
});

it('refuses to pause a product owned by someone else', function (): void {
    $mine = User::factory()->create();
    $theirs = Product::factory()->create(['active' => true]);

    $this->actingAs($mine);

    // A forged id in a Livewire call is not covered by the scoped list the page
    // was rendered from — the policy is what refuses it.
    livewire(ProductList::class)
        ->call('togglePaused', $theirs->id)
        ->assertForbidden();

    expect($theirs->fresh()?->active)->toBeTrue();
});

it('pauses and resumes a product it owns', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'active' => true]);

    $this->actingAs($user);

    livewire(ProductList::class)->call('togglePaused', $product->id);
    expect($product->fresh()?->active)->toBeFalse();

    livewire(ProductList::class)->call('togglePaused', $product->id);
    expect($product->fresh()?->active)->toBeTrue();
});

it('searches by title', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Dish soap']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('search', 'arabica')
        ->assertSee('Arabica beans')
        ->assertDontSee('Dish soap');
});

it('sorts by name, and ignores a sort key it does not offer', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Beta', 'created_at' => now()->subDay()]);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'alpha', 'created_at' => now()]);

    $this->actingAs($user);

    // Lowercase first: Postgres orders capitals ahead of lowercase, so a bare
    // ORDER BY title would put "Beta" in front of "alpha".
    livewire(ProductList::class)
        ->set('sort', 'title')
        ->assertSeeInOrder(['alpha', 'Beta']);

    // `sort` arrives from the URL, so a key the dropdown does not offer must
    // not reach the query. It falls back to newest first.
    livewire(ProductList::class)
        ->set('sort', 'user_id')
        ->assertOk()
        ->assertSeeInOrder(['alpha', 'Beta']);
});

it('sorts by the biggest drop, with products that never dropped last', function (): void {
    $user = User::factory()->create();
    $small = Product::factory()->create(['user_id' => $user->id, 'title' => 'Small drop']);
    $big = Product::factory()->create(['user_id' => $user->id, 'title' => 'Big drop']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Never dropped']);

    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $small->id, 'drop_pct' => 5]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $big->id, 'drop_pct' => 40]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('sort', 'biggest_drop')
        ->assertSeeInOrder(['Big drop', 'Small drop', 'Never dropped']);
});

it('sorts by the lowest price, with products that have none last', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Dearer', 'cheapest_price' => '9.00']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Cheaper', 'cheapest_price' => '2.00']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'No price yet', 'cheapest_price' => null]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('sort', 'cheapest_price')
        ->assertSeeInOrder(['Cheaper', 'Dearer', 'No price yet']);
});

it('offers the sort as a named choice, not as clickable table headers', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    // The headers asked a person to know they were clickable, and to guess
    // what a second click did.
    livewire(ProductList::class)
        ->assertSeeHtml('data-test="product-sort"')
        ->assertSee('Biggest drop first')
        ->assertDontSeeHtml('wire:click="sortBy');
});

it('offers the create action below the plan limit', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ProductList::class)->assertSee('Track a product');
});

it('states the limit instead of offering creation when the plan is full', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)->assertSee('You are following as many products as the free plan allows.');
});

it('renders an empty state and a no-match state', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(ProductList::class)->assertSee('Nothing tracked yet.');

    Product::factory()->create(['user_id' => $user->id, 'title' => 'Coffee']);

    livewire(ProductList::class)
        ->set('search', 'zzzz')
        ->assertSee('No product matches that search.');
});

it('shows the cheapest price and the shop count', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'title' => 'Coffee',
        'currency' => 'EUR',
        'cheapest_price' => '12.49',
    ]);
    Shop::factory()->count(2)->for($product)->create();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('€12.49')
        ->assertSee('2');
});

it('discloses bundle quantity beside the effective price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'cheapest_price' => '2.00']);
    $shop = Shop::factory()->for($product)->create([
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('2 for €4.00')
        ->assertSee('or €2.85 each')
        ->assertSee('title="Regular price"', escape: false)
        ->assertSeeText('€1.90 /l');
});

it('links the price and best-value shops out to the page that sells the product', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $shop = Shop::factory()->create([
        'product_id' => $product->id,
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'currency' => 'EUR',
    ]);

    $product->update(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '2.00']);

    $this->actingAs($user);

    livewire(ProductList::class)
        // The shop's own logo, so the row is recognisable before it is read.
        ->assertSeeHtml('favicons?domain=' . $shop->host)
        ->assertSeeHtml('href="' . $shop->url . '"');
});

it('filters by one category', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $user->id, 'title' => 'Kitten kibble']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Unsorted thing']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'food.coffee_tea')
        ->assertSee('Aroma Rood')
        ->assertDontSee('Kitten kibble')
        ->assertDontSee('Unsorted thing');
});

it('filters by a whole department', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->categorised(ProductCategory::Frozen)->create(['user_id' => $user->id, 'title' => 'Frozen peas']);
    Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $user->id, 'title' => 'Kitten kibble']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'food')
        ->assertSee('Aroma Rood')
        ->assertSee('Frozen peas')
        ->assertDontSee('Kitten kibble');
});

it('reads a category it does not know as all', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Unsorted thing']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'nonsense')
        ->assertSee('Aroma Rood')
        ->assertSee('Unsorted thing');
});

it('shows the empty no-match state when a department holds no products', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'pets')
        ->assertDontSee('Aroma Rood')
        ->assertSee('No product in that category.')
        ->assertDontSee('Nothing tracked yet.');
});

it('offers only the categories this account uses, grouped with an option for the whole department', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $user->id, 'title' => 'Kitten kibble']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Unsorted thing']);
    Product::factory()->categorised(ProductCategory::Cycling)->create(['title' => 'Somebody elses bike']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('All categories')
        ->assertSee('All food & drinks')
        ->assertSeeHtml('value="food.coffee_tea"')
        ->assertSeeHtml('value="pets.pet_food"')
        ->assertDontSeeHtml('value="food.frozen"')
        ->assertDontSeeHtml('value="sports.cycling"')
        ->assertDontSee('All sports & outdoor')
        ->assertSeeHtml('data-test="product-category-badge"')
        ->assertSee('Coffee & tea');
});

it('offers a filtered department with only its used categories, or none when it has no products', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'food')
        ->assertSee('All food & drinks')
        ->assertSeeHtml('value="food.coffee_tea"')
        ->assertDontSeeHtml('value="food.frozen"');

    livewire(ProductList::class)
        ->set('category', 'pets')
        ->assertSee('All pets')
        ->assertDontSeeHtml('value="pets.pet_food"')
        ->assertSee('No product in that category.');
});

it('keeps the current filter offered after its last product lost that category', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);

    $this->actingAs($user);

    $list = livewire(ProductList::class)->set('category', 'food.coffee_tea');

    $product->forceFill(['category' => null, 'category_set_by' => CategorySource::User])->save();

    $list->call('$refresh')
        ->assertSeeHtml('value="food.coffee_tea"')
        ->assertSee('No product in that category.');
});

it('reads the category filter from the URL', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Unsorted thing']);

    $this->actingAs($user);

    Livewire::withQueryParams(['category' => 'food.coffee_tea'])
        ->test(ProductList::class)
        ->assertSee('Aroma Rood')
        ->assertDontSee('Unsorted thing');

    Livewire::withQueryParams(['category' => 'nonsense'])
        ->test(ProductList::class)
        ->assertSee('Aroma Rood')
        ->assertSee('Unsorted thing');
});

it('shows how far a product in a drop sits below the price it alerted from', function (): void {
    $user = User::factory()->create();
    // Alerted at 7.99 (−20%), climbed back to 8.99 since: still latched, now −10%.
    $dropped = Product::factory()->create(['user_id' => $user->id, 'title' => 'Dropped coffee', 'cheapest_price' => '8.99', 'last_notified_price' => '7.99', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $dropped->id, 'reference_price' => '9.99', 'new_price' => '7.99', 'drop_pct' => 20.0, 'currency' => 'EUR']);
    $recovered = Product::factory()->create(['user_id' => $user->id, 'title' => 'Recovered tea', 'last_notified_price' => null]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $recovered->id, 'drop_pct' => 35.0]);

    $this->actingAs($user);

    $html = livewire(ProductList::class)
        ->assertSee('−10%')
        ->assertDontSee('−20%')
        ->assertSee('Was €9.99')
        ->assertDontSee('−35%')
        ->html();

    expect(substr_count($html, 'data-test="drop-badge"'))->toBe(1);
});
