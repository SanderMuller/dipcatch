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
        ->set('search', '%')
        ->assertSee('100% cocoa')
        ->assertDontSee('Plain tea')
        ->set('search', '_')
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
        ->assertSeeHtml('href="' . route('app.products.create-manual') . '"')
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
        ->toMatch('/<input[^>]*placeholder="Search pages and products…"[^>]*\sautofocus/');
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
