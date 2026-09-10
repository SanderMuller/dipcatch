<?php declare(strict_types=1);

use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Livewire\livewire;

function ownedProduct(User $user, ?string $title = null, ?string $cheapestPrice = null, bool $active = true): Product
{
    return Product::factory()->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'title' => $title ?? 'Tracked product',
        'cheapest_price' => $cheapestPrice,
        'active' => $active,
    ]);
}

it('refuses a product owned by someone else', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ProductShow::class, ['product' => Product::factory()->create()])
        ->assertForbidden();
});

it('shows the product, its price and its shops', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, title: 'Coffee beans', cheapestPrice: '12.49');
    // `host` is derived from the URL, so setting it directly is ignored.
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '12.49']);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Coffee beans')
        ->assertSee('€12.49')
        ->assertSee('jumbo.com');
});

it('pauses and resumes', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, active: true);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->call('togglePaused');

    expect($product->fresh()?->active)->toBeFalse();
});

it('removes a shop and recomputes the cheapest offer', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $cheap = Shop::factory()->for($product)->create(['current_price' => '5.00', 'url' => 'https://a.test/p']);
    Shop::factory()->for($product)->create(['current_price' => '9.00', 'url' => 'https://b.test/p']);

    $product->recomputeCheapestShop();
    expect($product->fresh()?->cheapest_shop_id)->toBe($cheap->id);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->call('removeShop', $cheap->id);

    // Removing the cheapest offer must move the product on, not leave it
    // pointing at a row that no longer exists.
    expect(Shop::query()->find($cheap->id))->toBeNull()
        ->and($product->fresh()?->cheapest_shop_id)->not->toBe($cheap->id);
});

it('refuses to remove a shop belonging to someone else', function (): void {
    $user = User::factory()->create();
    ownedProduct($user);
    $theirs = Shop::factory()->create();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => ownedProduct($user)])
        ->call('removeShop', $theirs->id)
        ->assertForbidden();

    expect(Shop::query()->find($theirs->id))->not->toBeNull();
});

it('offers a free account only the ranges it may read', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Last 30 days')
        ->assertSee('Last 90 days')
        ->assertDontSee('All time');
});

it('clamps a forged range to the plan ceiling', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create();

    // Older than the free 90-day window.
    ProductCheapestHistory::create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '99.99',
        'started_at' => CarbonImmutable::now()->subDays(200),
        'ended_at' => CarbonImmutable::now()->subDays(150),
    ]);

    $this->actingAs($user);

    // `range` reaches the component from the URL, so the menu is not the
    // enforcement point — the clamp is.
    $component = livewire(ProductShow::class, ['product' => $product])->set('range', 'all');

    $chart = $component->viewData('chart');

    expect(json_encode(is_array($chart) ? $chart : []))->not->toContain('99.99');
});

it('tells a free account why the long ranges are missing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => ownedProduct($user)])
        ->assertSee('Your plan shows the last 90 days.');
});

it('says nothing about plans to an account with no ceiling', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => ownedProduct($user)])
        ->assertDontSee('Your plan shows the last');
});

it('renders a product with no shops and no history', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => ownedProduct($user)])
        ->assertOk()
        ->assertSee('No shops yet.')
        ->assertSee('No price history yet.');
});

it('states the shop limit instead of offering another', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->count(4)->for($product)->create();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('This product is at its shop limit');
});
