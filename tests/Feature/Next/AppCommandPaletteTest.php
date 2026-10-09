<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\AppCommandPalette;
use App\Models\EmptySearch;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

it('lists app pages and this accounts recent products', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Product::factory()->create(['title' => 'Someone elses coffee']);

    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->assertSee('Dashboard')
        ->assertSee('Arabica beans')
        ->assertDontSee('Someone elses coffee');
});

it('lets a search for an assistant find Connections, and lists the shopping list and stats', function (): void {
    $this->actingAs(User::factory()->create());

    // Flux filters on an item's text and its `keywords` attribute.
    livewire(AppCommandPalette::class)
        ->assertSeeHtml('keywords="mcp claude chatgpt openai assistant ai agent integration connect"')
        ->assertSeeHtml('href="' . route('app.shopping-list') . '"')
        ->assertSeeHtml('href="' . route('app.stats') . '"');
});

it('finds an old product by name, not only the eight newest', function (): void {
    $user = User::factory()->create();
    $old = Product::factory()->for($user)->create(['title' => 'Arabica beans', 'created_at' => now()->subYear()]);
    Product::factory()->for($user)->count(9)->create();

    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->assertDontSee('Arabica beans')
        ->set('search', 'ARABICA')
        ->assertSee('Arabica beans')
        ->assertSeeHtml('href="' . route('app.products.show', $old) . '"');
});

it('finds products by shop and by category', function (): void {
    $user = User::factory()->create();
    $atJumbo = Product::factory()->for($user)->create(['title' => 'Coffee']);
    Shop::factory()->for($atJumbo)->create(['url' => 'https://www.jumbo.com/p/coffee']);
    Product::factory()->for($user)->categorised(ProductCategory::DairyEggs)->create(['title' => 'Milk']);
    Product::factory()->for($user)->create(['title' => 'Tea']);

    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'jumbo')
        ->assertSee('Coffee')
        ->assertDontSee('Tea')
        ->set('search', 'dairy')
        ->assertSee('Milk')
        ->assertDontSee('Coffee');
});

it('treats LIKE wildcards in the search as plain text', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => '100% cocoa']);
    Product::factory()->for($user)->create(['title' => 'Plain tea']);

    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', '0%')
        ->assertSee('100% cocoa')
        ->assertDontSee('Plain tea')
        ->set('search', '0_')
        ->assertDontSee('100% cocoa')
        ->assertDontSee('Plain tea');
});

it('never finds another account\'s product', function (): void {
    $this->actingAs(User::factory()->create());
    Product::factory()->create(['title' => 'Someone elses coffee']);

    livewire(AppCommandPalette::class)->set('search', 'coffee')->assertDontSee('Someone elses coffee');
});

it('lists the settings pages and the other pages people look for', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(AppCommandPalette::class)
        ->assertSeeHtml('href="' . route('security.edit') . '"')
        ->assertSeeHtml('href="' . route('appearance.edit') . '"')
        ->assertSeeHtml('href="' . e(route('app.products.create', ['mode' => 'manual'])) . '"')
        ->assertSeeHtml('href="' . route('shops') . '"')
        ->assertSeeHtml('keywords="password change password two-factor');
});

it('offers Upgrade to Pro only to a free account while Pro is on sale', function (): void {
    configureStripe();
    $this->actingAs(User::factory()->create());

    livewire(AppCommandPalette::class)->assertSee('Upgrade to Pro');

    $this->actingAs(User::factory()->create(['comped_until' => now()->addYear()]));

    livewire(AppCommandPalette::class)->assertDontSee('Upgrade to Pro');

    config()->set('plans.enabled', false);
    $this->actingAs(User::factory()->create());

    livewire(AppCommandPalette::class)->assertDontSee('Upgrade to Pro');
});

it('focuses the search field when the palette opens, so you can type at once', function (): void {
    $this->actingAs(User::factory()->create());

    // The modal focuses the element marked autofocus; without it focus stays on the page.
    expect(livewire(AppCommandPalette::class)->html())
        ->toMatch('/<input[^>]*placeholder="Search pages, products and shops…"[^>]*\sautofocus/');
});

it('records a search that found nothing, once per person and term, counting repeats', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->call('logEmptySearch', '  Stroop   Wafel ')
        ->call('logEmptySearch', 'stroop wafel');

    expect(EmptySearch::query()->sole()->only(['user_id', 'term', 'times']))->toBe(['user_id' => $user->id, 'term' => 'stroop wafel', 'times' => 2]);
});

it('does not record a search that matches a product by title or shop, or one too short to mean much', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Shop::factory()->for($product)->create(['url' => 'https://beanshop.test/p/1']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->call('logEmptySearch', 'arabica')
        ->call('logEmptySearch', 'beanshop')
        ->call('logEmptySearch', 'zz');

    expect(EmptySearch::query()->count())->toBe(0);
});

it('stops recording after thirty empty searches a minute', function (): void {
    $this->actingAs(User::factory()->create());
    $palette = livewire(AppCommandPalette::class);

    foreach (range(1, 35) as $index) {
        $palette->call('logEmptySearch', "nothing {$index}");
    }

    expect(EmptySearch::query()->count())->toBe(30);
});

it('forgets an empty search six months after it was last searched', function (): void {
    $user = User::factory()->create();
    EmptySearch::record($user, 'old search');
    EmptySearch::query()->update(['last_searched_at' => now()->subMonths(7)]);
    EmptySearch::record($user, 'recent search');

    $this->artisan('model:prune', ['--model' => [EmptySearch::class]]);

    expect(EmptySearch::query()->pluck('term')->all())->toBe(['recent search']);
});

it('shows a product with its photo, best price and shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Shop::factory()->for($product)->create(['url' => 'https://beans.test/p/1', 'current_price' => '4.99', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'arabica')
        ->assertSeeHtml('data-test="command-product"')
        ->assertSeeText('€4.99 · beans.test');
});

it('lists a matching shop once, by host or by name, leading to the products at that shop', function (): void {
    config()->set('site.shop_names', ['ah.nl' => 'Albert Heijn']);
    $user = User::factory()->create();
    foreach (['Coffee', 'Tea'] as $title) {
        Shop::factory()->for(Product::factory()->for($user)->create(['title' => $title]))->create(['url' => "https://www.supspace.nl/p/{$title}"]);
    }
    Shop::factory()->for(Product::factory()->for($user)->create())->create(['url' => 'https://www.ah.nl/p/1']);
    $this->actingAs($user);

    $palette = livewire(AppCommandPalette::class)->set('search', 'supspace');
    expect(substr_count($palette->html(), 'data-test="command-shop"'))->toBe(1);
    $palette->assertSeeHtml('href="' . route('app.products.index', ['shop' => 'supspace.nl']) . '"')
        ->set('search', 'albert')
        ->assertSeeHtml('href="' . route('app.products.index', ['shop' => 'ah.nl']) . '"')
        ->assertSeeText('Albert Heijn · Your products at this shop');
});

it('lists no shop the account follows nothing at, and no switched-off shop', function (): void {
    $user = User::factory()->create();
    Shop::factory()->for(Product::factory()->create())->create(['url' => 'https://supspace.nl/p/1']);
    Shop::factory()->inactive()->for(Product::factory()->for($user)->create())->create(['url' => 'https://supspace.nl/p/2']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)->set('search', 'supspace')->assertDontSeeHtml('data-test="command-shop"');
});

it('puts pages, shops and products each under a heading, and leaves out a group with nothing in it', function (): void {
    $user = User::factory()->create();
    Shop::factory()->for(Product::factory()->for($user)->create(['title' => 'Protein bar']))->create(['url' => 'https://supspace.nl/p/1']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'supspace')
        ->assertSeeHtmlInOrder(['data-test="command-group-pages"', 'data-test="command-group-shops"', 'data-test="command-group-products"', 'Protein bar'])
        ->set('search', 'protein')
        ->assertDontSeeHtml('data-test="command-group-shops"')
        ->assertSeeHtml('data-test="command-group-products"');
});

it('starts searching at two letters, and shows the newest products before that', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Arabica beans', 'created_at' => now()->subYear()]);
    Product::factory()->for($user)->count(8)->create(['title' => 'Tea']);
    Shop::factory()->for(Product::factory()->for($user)->create(['title' => 'Tea']))->create(['url' => 'https://ah.nl/p/1']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'a')
        ->assertDontSee('Arabica beans')
        ->assertDontSeeHtml('data-test="command-shop"')
        ->set('search', 'ar')
        ->assertSee('Arabica beans');
});

it('lists a department or category the account has products in, leading to the products in it', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->categorised(ProductCategory::PetFood)->create(['title' => 'Kibble']);
    Product::factory()->for($user)->categorised(ProductCategory::CoffeeTea)->create(['title' => 'Arabica beans']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'pet')
        ->assertSeeHtml('href="' . route('app.products.index', ['category' => 'pets']) . '"')
        ->assertDontSeeHtml('href="' . route('app.products.index', ['category' => ProductCategory::PetFood->value]) . '"')
        ->set('search', 'dairy')
        ->assertDontSeeHtml('data-test="command-group-categories"');
});

it('finds a product at a shop by the shop name, and does not record that search as empty', function (): void {
    config()->set('site.shop_names', ['ah.nl' => 'Albert Heijn']);
    $user = User::factory()->create();
    Shop::factory()->for(Product::factory()->for($user)->create(['title' => 'Dish soap']))->create(['url' => 'https://www.ah.nl/p/1']);
    Shop::factory()->for(Product::factory()->for($user)->create(['title' => 'Coffee']))->create(['url' => 'https://www.jumbo.com/p/1']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->set('search', 'albert')
        ->assertSeeHtml('href="' . route('app.products.show', Product::query()->where('title', 'Dish soap')->sole()) . '"')
        ->assertDontSee('Coffee')
        ->call('logEmptySearch', 'albert');

    expect(EmptySearch::query()->count())->toBe(0);
});

it('lists no category that only another account has products in', function (): void {
    Product::factory()->categorised(ProductCategory::DairyEggs)->create(['title' => 'Milk']);
    $this->actingAs(User::factory()->create());

    livewire(AppCommandPalette::class)->set('search', 'dairy')->assertDontSeeHtml('data-test="command-group-categories"');
});

it('shows a small copy of the product photo, where the shop can resize it', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Arabica beans', 'image_url' => 'https://static.ah.nl/dam/product/AHI_1?revLabel=1&rendition=800x800_WEBP&fileType=binary']);
    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->assertSeeHtml('rendition=200x200_WEBP')
        ->assertDontSeeHtml('rendition=800x800_WEBP');
});
