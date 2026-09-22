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
    // not reach the query. It falls back to the default: neither dropped, so
    // the newest comes first.
    livewire(ProductList::class)
        ->set('sort', 'user_id')
        ->assertOk()
        ->assertSeeInOrder(['alpha', 'Beta']);
});

it('sorts by the biggest drop by default, with products that never dropped last', function (): void {
    $user = User::factory()->create();
    $small = Product::factory()->create(['user_id' => $user->id, 'title' => 'Small drop']);
    $big = Product::factory()->create(['user_id' => $user->id, 'title' => 'Big drop']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Never dropped']);

    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $small->id, 'drop_pct' => 5]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $big->id, 'drop_pct' => 40]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSet('sort', 'biggest_drop')
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

it('shows the cheapest price and each shop that sells it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'title' => 'Coffee',
        'currency' => 'EUR',
        'cheapest_price' => '12.49',
    ]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/producten/coffee', 'current_price' => '12.49']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/producten/coffee', 'current_price' => '13.99']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('€12.49')
        ->assertSeeInOrder(['ah.nl', '€12.49', 'jumbo.com', '€13.99']);
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

it('marks a paused product as paused, with its price as the last one read', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Paused soap', 'active' => false, 'cheapest_price' => '4.79']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Active tea', 'active' => true]);

    $this->actingAs($user);

    $html = livewire(ProductList::class)
        ->assertSee('Last price read')
        ->assertSee('€4.79')
        // Pausing lives on the product page now, not on the card.
        ->assertDontSee('Pause tracking')
        ->assertDontSee('Resume tracking')
        ->html();

    expect(substr_count($html, 'data-test="paused-label"'))->toBe(1);
});

it('states a per-unit drop in its unit, not beside a pack price it was not measured on', function (): void {
    $user = User::factory()->create();
    // The reference is a 500 g pack at €6.25, so €12.50 a kilo; the card's price is a 200 g pack.
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Crisps', 'cheapest_price' => '1.69', 'last_notified_price' => '1.69', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'reference_price' => '6.25',
        'reference_unit_price' => '12.5000',
        'comparison_unit' => 'g',
        'new_price' => '1.69',
        'drop_pct' => 20.0,
        'currency' => 'EUR',
    ]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertDontSeeHtml('<del')
        ->assertSee('Was €12.50/kg')
        ->assertDontSee('€6.25');
});

it('lists the shop behind the card price first, even when an out-of-stock shop is lower', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/coffee', 'current_price' => '9.99', 'current_in_stock' => false]);
    $best = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/producten/coffee', 'current_price' => '12.49']);
    $product->forceFill(['cheapest_shop_id' => $best->id, 'cheapest_price' => '12.49'])->save();

    $this->actingAs($user);

    livewire(ProductList::class)->assertSeeInOrder(['ah.nl', '€12.49', 'lidl.nl', '€9.99']);
});

it('shows only products with a discount when asked', function (): void {
    $user = User::factory()->create();

    $dropped = productWithCheapestShop($user, 'In a drop', [], ['last_notified_price' => '2.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $dropped->id]);
    productWithCheapestShop($user, 'Promotion running', ['promotion_ends_at' => now()->addDays(3)]);
    productWithCheapestShop($user, 'Bundle read', ['current_price' => '2.00', 'single_item_price' => '2.85', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00']);
    productWithCheapestShop($user, 'Promotion ended', ['promotion_ends_at' => now()->subDay()]);
    productWithCheapestShop($user, 'Promotion not started', ['promotion_starts_at' => now()->addDay(), 'promotion_ends_at' => now()->addDays(5)]);
    // A bundle stored beside a price that is not the bundle price is not the deal on offer.
    productWithCheapestShop($user, 'Bundle not read', ['current_price' => '2.85', 'single_item_price' => '2.85', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00']);
    productWithCheapestShop($user, 'Full price', []);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('discounted', true)
        ->assertSee('In a drop')
        ->assertSee('Promotion running')
        ->assertSee('Bundle read')
        ->assertDontSee('Promotion ended')
        ->assertDontSee('Promotion not started')
        ->assertDontSee('Bundle not read')
        ->assertDontSee('Full price')
        ->set('discounted', false)
        ->assertSee('Full price');
});

it('counts a shop deal the same way the shop does', function (Closure $make, bool $counts): void {
    $user = User::factory()->create();
    $product = $make($user);
    $cheapest = $product instanceof Product ? $product->cheapestShop : null;

    // The SQL filter and the PHP rules must agree on every one of these.
    $php = $cheapest?->liveBundleOffer() !== null || $cheapest?->promotionWindow()?->isRunning() === true;
    expect($php)->toBe($counts);

    $this->actingAs($user);

    $list = livewire(ProductList::class)->set('discounted', true);
    $counts ? $list->assertSee('Checked') : $list->assertDontSee('Checked');
})->with([
    'bundle that rounds' => [fn (User $user): Product => productWithCheapestShop($user, 'Checked', ['current_price' => '1.67', 'single_item_price' => '1.99', 'bundle_quantity' => 3, 'bundle_total_price' => '5.00']), true],
    'bundle with no single price stored' => [fn (User $user): Product => productWithCheapestShop($user, 'Checked', ['current_price' => '2.00', 'single_item_price' => null, 'bundle_quantity' => 2, 'bundle_total_price' => '4.00']), false],
    'bundle no cheaper than one' => [fn (User $user): Product => productWithCheapestShop($user, 'Checked', ['current_price' => '2.00', 'single_item_price' => '2.00', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00']), false],
    'promotion that started' => [fn (User $user): Product => productWithCheapestShop($user, 'Checked', ['promotion_starts_at' => now()->subDay(), 'promotion_ends_at' => now()->addDay()]), true],
    'promotion that starts after it ends' => [fn (User $user): Product => productWithCheapestShop($user, 'Checked', ['promotion_starts_at' => now()->addDays(2), 'promotion_ends_at' => now()->addDay()]), false],
]);

it('ignores a deal at a shop that is not the cheapest', function (): void {
    $user = User::factory()->create();
    $product = productWithCheapestShop($user, 'Deal elsewhere', []);
    Shop::factory()->for($product)->create(['current_price' => '3.00', 'promotion_ends_at' => now()->addDays(3)]);

    $this->actingAs($user);

    livewire(ProductList::class)->set('discounted', true)->assertDontSee('Deal elsewhere');
});

it('says so when no product has a discount', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Full price']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('discounted', true)
        ->assertSee('No product has a discount right now.')
        // With another filter on, the message names the filter, not the whole list.
        ->set('status', 'paused')
        ->assertSee('No product in this filter has a discount right now.');
});

it('names the filter, not only the category, when more than one filter finds nothing', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'active' => true]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', 'food')
        ->set('status', 'paused')
        ->assertSee('No product matches this filter.')
        ->assertDontSee('No product in that category.');
});

/**
 * A product whose cheapest shop carries the given columns.
 *
 * @param  array<string, mixed>  $shop
 * @param  array<string, mixed>  $product
 */
function productWithCheapestShop(User $user, string $title, array $shop, array $product = []): Product
{
    $made = Product::factory()->create(['user_id' => $user->id, 'title' => $title]);
    $cheapest = Shop::factory()->for($made)->create(['current_price' => '2.00']);
    $cheapest->forceFill($shop)->save();
    $made->forceFill(['cheapest_shop_id' => $cheapest->id, 'cheapest_price' => $cheapest->current_price, ...$product])->save();

    return $made;
}
