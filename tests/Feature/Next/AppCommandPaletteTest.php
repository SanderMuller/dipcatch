<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\AppCommandPalette;
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
