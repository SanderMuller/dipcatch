<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Livewire\Dashboard;
use App\Livewire\Products\ProductList;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
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

it('draws the first page in the sort this browser last chose', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'alpha', 'created_at' => now()->subDay()]);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Beta', 'created_at' => now()]);

    $this->actingAs($user);

    // Restoring the sort in the browser re-sorted the list after it drew.
    // The server reads it, so the first response is already in that order.
    $this->withCookie('products_sort', 'title')->get(route('app.products.index'))
        ->assertOk()
        ->assertSeeInOrder(['alpha', 'Beta']);

    // A sort in the URL wins over the remembered one.
    $this->withCookie('products_sort', 'title')->get(route('app.products.index', ['sort' => 'created_at']))
        ->assertOk()
        ->assertSeeInOrder(['Beta', 'alpha']);

    // A cookie holding a key the list does not offer is ignored.
    $this->withCookie('products_sort', 'user_id')->get(route('app.products.index'))
        ->assertOk()
        ->assertSeeInOrder(['Beta', 'alpha']);
});

it('remembers a chosen sort for the next visit, and only a sort it offers', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ProductList::class)->set('sort', 'title');

    expect(Cookie::hasQueued('products_sort'))->toBeTrue()
        ->and(Cookie::queued('products_sort')?->getValue())->toBe('title');

    Cookie::unqueue('products_sort');

    livewire(ProductList::class)->set('sort', 'user_id');

    expect(Cookie::hasQueued('products_sort'))->toBeFalse();
});

it('sorts by the biggest drop by default, with products not in a drop last', function (): void {
    $user = User::factory()->create();
    $small = Product::factory()->create(['user_id' => $user->id, 'title' => 'Small drop', 'last_notified_price' => '9.50', 'last_notified_at' => now()]);
    $big = Product::factory()->create(['user_id' => $user->id, 'title' => 'Big drop', 'last_notified_price' => '6.00', 'last_notified_at' => now()]);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Never dropped']);

    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $small->id, 'drop_pct' => 5]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $big->id, 'drop_pct' => 40]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSet('sort', 'biggest_drop')
        ->assertSeeInOrder(['Big drop', 'Small drop', 'Never dropped']);
});

it('drops a product out of the drop sort once it is no longer in a drop', function (): void {
    $user = User::factory()->create();
    $inDrop = Product::factory()->create(['user_id' => $user->id, 'title' => 'Still down 10', 'last_notified_price' => '9.00', 'last_notified_at' => now()->subDays(2)]);
    // Dropped 60% once, from a shop since removed; the price is back and the
    // latch is clear.
    $recovered = Product::factory()->create(['user_id' => $user->id, 'title' => 'Recovered 60', 'last_notified_price' => null, 'created_at' => now()->subDays(5)]);

    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $inDrop->id, 'drop_pct' => 10]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $recovered->id, 'drop_pct' => 60]);

    $this->actingAs($user);

    livewire(ProductList::class)->assertSeeInOrder(['Still down 10', 'Recovered 60']);
});

it('ranks a product by its latest drop, not the biggest it ever had', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Down 8 now', 'last_notified_price' => '9.20', 'last_notified_at' => now()]);
    $other = Product::factory()->create(['user_id' => $user->id, 'title' => 'Down 20 now', 'last_notified_price' => '8.00', 'last_notified_at' => now()]);

    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'drop_pct' => 50, 'fired_at' => now()->subMonths(2)]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'drop_pct' => 8, 'fired_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $other->id, 'drop_pct' => 20, 'fired_at' => now()]);

    $this->actingAs($user);

    livewire(ProductList::class)->assertSeeInOrder(['Down 20 now', 'Down 8 now']);
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
    Shop::factory()->for($product)->create(['current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
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

    $card = withoutCardDetails(livewire(ProductList::class)->html());

    expect($card)->not->toContain('<del')
        ->toMatch('/€8\.45 \/kg.*Was €12\.50 \/kg/s')
        ->not->toContain('€6.25');
});

it('shows no drop for a pack-price alert on a product that now compares per unit', function (): void {
    $user = User::factory()->create();
    // Alerted on the pack price before the product had a pack size; now it
    // leads per kilo, and "Was €2.19" beside "€8.45 /kg" would compare two things.
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Crisps', 'cheapest_price' => '1.69', 'last_notified_price' => '1.69', 'last_notified_at' => now()]);
    Shop::factory()->for($product)->create(['current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    $product->forceFill(['best_value_price' => '1.69', 'best_value_pack_quantity' => '200.00', 'best_value_pack_unit' => 'g'])->save();
    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'reference_price' => '2.19',
        'comparison_unit' => null,
        'new_price' => '1.69',
        'drop_pct' => 22.8,
        'currency' => 'EUR',
    ]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('€8.45 /kg')
        ->assertDontSeeHtml('data-test="drop-badge"')
        ->assertDontSee('€2.19')
        ->set('discounted', true)
        ->assertDontSee('Crisps');
});

it('leads the card with the best value and notes the lowest pack price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Tablets']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://kruidvat.nl/p/1', 'current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://etos.nl/p/1', 'current_price' => '26.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/1', 'current_price' => '13.49', 'pack_quantity' => '400.00', 'pack_unit' => 'piece']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $html = livewire(ProductList::class)->html();

    // The winner is the first shop row even with four shops and a cheaper pack
    // two rows down; the rest follow by price per tablet.
    expect(preg_replace('/\s+/', ' ', strip_tags($html)))
        ->toContain('€0.0275 /piece')
        ->toContain('€21.99 for 800 pieces')
        ->toContain('Lowest price €12.99 for 400 pieces at ah.nl')
        ->toMatch('/kruidvat\.nl.*€0\.0275 \/piece.*€21\.99.*ah\.nl.*€0\.0325 \/piece.*bol\.com.*€0\.0337 \/piece/s');
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

it('shows no drop for a product whose price is back at the alert reference', function (): void {
    $user = User::factory()->create();
    // Alerted from 11.95, and the price is back at 11.95: still latched, but 0% down.
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Back at reference', 'cheapest_price' => '11.95', 'last_notified_price' => '10.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => '11.95', 'new_price' => '10.00', 'drop_pct' => 16.3, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('Back at reference')
        ->assertDontSeeHtml('data-test="drop-badge"')
        ->assertDontSee('−0%');
});

it('sorts a product back at its alert reference with the ones not in a drop', function (): void {
    $user = User::factory()->create();
    // Alerted from 11.95 at 16%, and back at 11.95: latched, but 0% down now.
    $back = Product::factory()->create(['user_id' => $user->id, 'title' => 'Back at reference', 'cheapest_price' => '11.95', 'last_notified_price' => '10.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $back->id, 'reference_price' => '11.95', 'new_price' => '10.00', 'drop_pct' => 16.3, 'currency' => 'EUR']);
    $down = Product::factory()->create(['user_id' => $user->id, 'title' => 'Down 5 now', 'cheapest_price' => '9.50', 'last_notified_price' => '9.50', 'last_notified_at' => now(), 'created_at' => now()->subDay()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $down->id, 'reference_price' => '10.00', 'new_price' => '9.50', 'drop_pct' => 5, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(ProductList::class)->assertSeeInOrder(['Down 5 now', 'Back at reference']);
});

it('never lists a sold-out shop ahead of one that can be bought from', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Tablets']);
    Shop::factory()->for($product)->create(['url' => 'https://kruidvat.nl/p/1', 'current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://etos.nl/p/1', 'current_price' => '9.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece', 'current_in_stock' => false]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    // Etos is cheapest per tablet but sold out: it follows the live shops, on
    // its pack price, with no figure per tablet.
    expect(preg_replace('/\s+/', ' ', strip_tags(withoutCardDetails(livewire(ProductList::class)->html()))))
        ->toMatch('/kruidvat\.nl €0\.0275 \/piece.*ah\.nl €0\.0325 \/piece.*etos\.nl[^€]*€9\.99/s')
        ->not->toContain('€0.0125');
});

it('leaves a drop the price has climbed back out of off "Only discounts"', function (): void {
    $user = User::factory()->create();
    // Latched, but the price is back at the reference: nothing to show, so not a discount.
    $back = Product::factory()->create(['user_id' => $user->id, 'title' => 'Back at reference', 'cheapest_price' => '11.95', 'last_notified_price' => '10.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $back->id, 'reference_price' => '11.95', 'new_price' => '10.00', 'drop_pct' => 16.3, 'currency' => 'EUR']);
    // A per-unit alert whose best value is back above the reference per kilo.
    $unitBack = Product::factory()->create(['user_id' => $user->id, 'title' => 'Back per kilo', 'cheapest_price' => '2.20', 'best_value_price' => '2.20', 'best_value_pack_quantity' => '200.00', 'best_value_pack_unit' => 'g', 'last_notified_price' => '10.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $unitBack->id, 'reference_price' => null, 'reference_unit_price' => '10.9500', 'new_unit_price' => '10.0000', 'comparison_unit' => 'g', 'new_price' => '2.00', 'drop_pct' => 8.7, 'currency' => 'EUR']);
    // Still down per kilo: 8.45 against 10.95.
    $unitDown = Product::factory()->create(['user_id' => $user->id, 'title' => 'Down per kilo', 'cheapest_price' => '1.69', 'best_value_price' => '1.69', 'best_value_pack_quantity' => '200.00', 'best_value_pack_unit' => 'g', 'last_notified_price' => '8.45', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $unitDown->id, 'reference_price' => null, 'reference_unit_price' => '10.9500', 'new_unit_price' => '8.4500', 'comparison_unit' => 'g', 'new_price' => '1.69', 'drop_pct' => 22.8, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('discounted', true)
        ->assertSee('Down per kilo')
        ->assertDontSee('Back at reference')
        ->assertDontSee('Back per kilo');
});

it('states a promotion the card is listed under "Only discounts" for', function (): void {
    $user = User::factory()->create();
    productWithCheapestShop($user, 'Remia Friteslijn', ['host' => 'ah.nl', 'current_price' => '1.69', 'promotion_label' => '25% korting', 'promotion_ends_at' => now()->addDays(4)]);

    $this->actingAs($user);

    $card = preg_replace('/\s+/', ' ', strip_tags(withoutCardDetails(livewire(ProductList::class)->set('discounted', true)->html())));

    expect($card)->toContain('Remia Friteslijn')
        ->toContain('Deal 25% korting until');
});

it('names the deal of the lowest-price shop in its note', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Crisps']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'promotion_label' => 'Bonus', 'promotion_ends_at' => now()->addDays(4)]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $card = preg_replace('/\s+/', ' ', strip_tags(withoutCardDetails(livewire(ProductList::class)->set('discounted', true)->html())));

    expect($card)->toContain('Lowest price €1.69 for 200 g at ah.nl · Bonus until')
        ->not->toContain('Deal Bonus');
});

it('shows the full discount and every shop on hover', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Crisps', 'last_notified_price' => '8.45', 'last_notified_at' => now()]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '2.00', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'single_item_price' => '2.85', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00']);
    Shop::factory()->for($product)->create(['url' => 'https://plus.nl/p/1', 'current_price' => '2.29', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'current_in_stock' => false]);
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1', 'current_price' => '2.49', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    $product->refresh()->recomputeCheapestShop();
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => null, 'reference_unit_price' => '10.9500', 'new_unit_price' => '8.4500', 'comparison_unit' => 'g', 'new_price' => '1.69', 'drop_pct' => 22.8, 'currency' => 'EUR', 'fired_at' => now()]);

    $this->actingAs($user);

    preg_match('#data-test="product-card-details".*?</ui-tooltip>#s', livewire(ProductList::class)->html(), $match);
    $details = preg_replace('/\s+/', ' ', strip_tags($match[0] ?? ''));

    expect($details)->toContain('−23% Down per kilo · since')
        ->toContain('€10.95 /kg → €8.45 /kg')
        ->toContain('Deal jumbo.com · 2 for €4.00 · or €2.85 each')
        // Every shop, the fourth one too, which the card has no room for.
        ->toMatch('/\d shops/')
        ->toMatch('/ah\.nl Best value In stock.*€8\.45 \/kg €1\.69 for 200 g/')
        ->toContain('dirk.nl')
        ->toContain('Out of stock');
});

it('resolves the hover details without a query per card', function (): void {
    $queriesFor = function (int $count): int {
        $user = User::factory()->create();

        foreach (range(1, $count) as $index) {
            $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => "Crisps {$index}"]);
            Shop::factory()->for($product)->create(['url' => "https://ah.nl/p/{$index}", 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'promotion_ends_at' => now()->addDays(3)]);
            Shop::factory()->for($product)->create(['url' => "https://lidl.nl/p/{$index}", 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
            $product->refresh()->recomputeCheapestShop();
        }

        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(ProductList::class)->assertSeeHtml('data-test="product-card-details"');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($queriesFor(4))->toBe($queriesFor(1));
});

it('lists a drop from half a percent, as the badge rounds it', function (): void {
    $user = User::factory()->create();
    // 1 off 200 is 0.5%: the badge reads −1%. 1 off 250 is 0.4%: no badge.
    foreach ([['Half a percent', '199.00', '200.00'], ['Under half', '249.00', '250.00']] as [$title, $now, $was]) {
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => $title, 'cheapest_price' => $now, 'last_notified_price' => $now, 'last_notified_at' => now()]);
        PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => $was, 'new_price' => $now, 'drop_pct' => 1.0, 'currency' => 'EUR']);
    }

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('discounted', true)
        ->assertSee('Half a percent')
        ->assertDontSee('Under half');
});

it('lists a deal at the best-value shop, which the card leads with', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Crisps']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g', 'promotion_label' => 'Actie', 'promotion_ends_at' => now()->addDays(3)]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $card = preg_replace('/\s+/', ' ', strip_tags(withoutCardDetails(livewire(ProductList::class)->set('discounted', true)->html())));

    expect($card)->toContain('Crisps')->toContain('Deal Actie until');
});

it('states a bundle as today\'s deal without a promotion window that has ended', function (): void {
    $user = User::factory()->create();
    productWithCheapestShop($user, 'Fanta', [
        'host' => 'jumbo.com', 'current_price' => '2.00', 'single_item_price' => '2.85', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00',
        'promotion_label' => '2 voor 4,00', 'promotion_ends_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    $card = preg_replace('/\s+/', ' ', strip_tags(withoutCardDetails(livewire(ProductList::class)->set('discounted', true)->html())));

    expect($card)->toContain('Deal 2 for €4.00 · or €2.85 each')->not->toContain('ended');
});

it('states a running promotion on a dashboard card too', function (): void {
    $user = User::factory()->create();
    productWithCheapestShop($user, 'Remia Friteslijn', ['host' => 'ah.nl', 'current_price' => '1.69', 'promotion_label' => '25% korting', 'promotion_ends_at' => now()->addDays(4)]);

    $this->actingAs($user);

    $html = withoutCardDetails(livewire(Dashboard::class)->html());
    $card = preg_replace('/\s+/', ' ', strip_tags($html));

    expect($card)->toContain('Deal 25% korting until');

    preg_match_all('#<article\b.*?</article>#s', $html, $cards);

    expect($cards[0])->not->toBeEmpty();

    foreach ($cards[0] as $article) {
        expect(substr_count(strip_tags($article), 'until'))->toBe(1);
    }
});

it('keeps an announced deal on a dashboard card', function (): void {
    $user = User::factory()->create();
    productWithCheapestShop($user, 'Remia Friteslijn', ['host' => 'ah.nl', 'current_price' => '1.69', 'promotion_label' => '25% korting', 'promotion_starts_at' => now()->addDays(2), 'promotion_ends_at' => now()->addDays(8)]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSee('Upcoming deal');
});

it('lists a paused shop in the hover, marked as paused', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Crisps']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.69']);
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1', 'current_price' => '1.49', 'active' => false]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    preg_match('#data-test="product-card-details".*?</ui-tooltip>#s', livewire(ProductList::class)->html(), $match);

    expect(preg_replace('/\s+/', ' ', strip_tags($match[0] ?? '')))->toContain('2 shops')->toMatch('/dirk\.nl Paused/');
});
