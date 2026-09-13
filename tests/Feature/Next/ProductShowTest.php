<?php declare(strict_types=1);

use App\Livewire\Products\ProductShow;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Support\Favicon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

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

it('discloses bundle terms in headline and shop row', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '2.00');
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'pack_quantity' => 1,
        'pack_unit' => 'piece',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product])
        ->assertSee('2 for €4.00')
        ->assertSee('or €2.85 each');

    expect(substr_count($component->html(), '2 for €4.00'))->toBeGreaterThanOrEqual(3);
});

it('pauses and resumes', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, active: true);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->call('togglePaused');

    expect($product->fresh()?->active)->toBeFalse();
});

/**
 * The `x-shop-link` anchors pointing at one shop, and what each wraps.
 *
 * @return list<string> the inner HTML of each matching anchor
 */
function shopLinksTo(string $html, string $url): array
{
    preg_match_all(
        '/<a href="' . preg_quote($url, '/') . '" target="_blank" rel="noopener noreferrer"[^>]*>(.*?)<\/a>/',
        (string) preg_replace('/\s+/', ' ', $html),
        $matches,
    );

    return $matches[1];
}

/**
 * The summary tiles alone. The same shop is linked from the tiles and from
 * the tracked-shops table, so a whole-page count cannot tell them apart.
 */
function summaryTiles(string $html): string
{
    preg_match('/<dl\b[^>]*>.*?<\/dl>/s', (string) preg_replace('/\s+/', ' ', $html), $matches);

    return $matches[0] ?? '';
}

it('shows a shop the add-shop form just added, without a page reload', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '12.49');
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '12.49']);

    $this->actingAs($user);

    $page = livewire(ProductShow::class, ['product' => $product])->assertDontSee('picnic.nl');

    // What AddShop does on Confirm: write the shop, recompute, announce it.
    Shop::factory()->for($product)->create(['url' => 'https://picnic.nl/p/9', 'current_price' => '9.99']);
    $product->recomputeCheapestShop();

    $page->dispatch('shop-added', offerId: '1')
        ->assertSee('picnic.nl')
        ->assertSee('€9.99');

    // The table row alone satisfies both assertions above, so the summary
    // tile is checked on its own: it reads `cheapest_shop_id`, which only a
    // rehydrated product carries.
    expect(shopLinksTo(summaryTiles($page->html()), 'https://picnic.nl/p/9'))->toHaveCount(1);
});

it('keeps the add-shop disclosure out of the morph so it survives the refresh', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product])->html());

    // A tripwire for the attribute, not the behaviour: the form staying open
    // through the `shop-added` re-render is browser-only. Livewire's morph
    // strips `open` from a <details> without this. Anchored to the element,
    // because a Flux <dialog> on this page carries the same attribute.
    expect($html)->toContain('<details class="group w-full" wire:ignore.self');
});

it('replaces the add-shop form with the limit callout when the added shop fills the plan', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->count(3)->for($product)->create();

    $this->actingAs($user);

    $page = livewire(ProductShow::class, ['product' => $product])->assertSee('Add a shop');

    Shop::factory()->for($product)->create();

    $page->dispatch('shop-added', offerId: '1')
        ->assertDontSee('Add a shop')
        ->assertSee('This product is at its shop limit');
});

it('links the cheapest and best-value shops out to the shop itself', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    // Cheapest on the sticker, worst per gram — so the two tiles name
    // different shops and one cannot satisfy both assertions.
    $cheapest = Shop::factory()->for($product)->create([
        'url' => 'https://picnic.nl/p/9',
        'current_price' => '5.00',
        'pack_quantity' => '100.00',
        'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/9',
        'current_price' => '8.00',
        'pack_quantity' => '400.00',
        'pack_unit' => 'g',
    ]);
    $product->recomputeCheapestShop();

    expect($product->fresh()?->cheapest_shop_id)->toBe($cheapest->id)
        ->and($product->fresh()?->bestValueShop()?->host)->toBe('jumbo.com');

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product])->html());
    $tiles = summaryTiles($html);

    // One link per tile. Each names its shop and shows its favicon, so a bare
    // icon or a lost label fails here.
    expect(shopLinksTo($tiles, 'https://picnic.nl/p/9'))->toHaveCount(1)
        ->and(shopLinksTo($tiles, 'https://jumbo.com/p/9'))->toHaveCount(1)
        ->and(shopLinksTo($tiles, 'https://picnic.nl/p/9')[0])
        ->toContain('picnic.nl')
        ->toContain(e(Favicon::url($cheapest->host)));
});

it('links every tracked shop row out to the shop itself', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://picnic.nl/p/9', 'current_price' => '5.00']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/9', 'current_price' => '8.00', 'notes' => 'Free delivery over €35']);

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product])->html());
    // The table only: the tiles link the same shops, and the Open menu item
    // carries no `rel`, so neither can stand in for a row.
    $table = str_replace(summaryTiles($html), '', $html);

    expect(shopLinksTo($table, 'https://picnic.nl/p/9'))->toHaveCount(1)
        ->and(shopLinksTo($table, 'https://jumbo.com/p/9'))->toHaveCount(1)
        ->and(shopLinksTo($table, 'https://jumbo.com/p/9')[0])
        ->toContain('jumbo.com')
        ->toContain(e(Favicon::url('jumbo.com')));

    // The notes indicator sits beside the link, not inside it.
    expect(shopLinksTo($table, 'https://jumbo.com/p/9')[0])->not->toContain('notes_indicator')
        ->and($html)->toContain('notes_indicator');
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

it('stops advertising the old price when the re-check is rate limited', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $rival = Shop::factory()->for($product)->create([
        'url' => 'https://rival.example.com/p/1',
        'current_price' => '12.00',
    ]);
    $repointed = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '5.00',
    ]);

    $product->recomputeCheapestShop();
    expect($product->cheapest_shop_id)->toBe($repointed->id);

    // A 429 makes the sync re-check give up before it writes a price, so the
    // component's own recompute is the only one that runs.
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/2' => Http::response('slow down', 429, ['Retry-After' => '120']),
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('saveShopUrl', $repointed->id, 'https://shop.example.com/p/2')
        ->assertSet('shopMessage', 'Shop URL updated. The shop was too busy to read now, so the price follows with the next scheduled check.');

    expect($repointed->refresh()->current_price)->toBeNull()
        ->and($product->refresh()->cheapest_shop_id)->toBe($rival->id)
        ->and((string) $product->cheapest_price)->toBe('12.00')
        ->and(PriceDropEvent::query()->count())->toBe(0);
});

it('reports the new price when the re-check does read the page', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '5.00',
    ]);

    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/2' => Http::response(jsonLdPage('9.00'), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('saveShopUrl', $shop->id, 'https://shop.example.com/p/2')
        ->assertSet('shopMessage', 'Shop URL updated and price re-checked');

    expect((string) $shop->refresh()->current_price)->toBe('9.00')
        ->and($shop->last_success_at)->not->toBeNull();
});

it('says the new page could not be read when the re-check fails', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'current_price' => '5.00',
    ]);

    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/2' => Http::response('gone', 404),
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('saveShopUrl', $shop->id, 'https://shop.example.com/p/2')
        ->assertSet('shopMessage', 'Shop URL updated, but no price could be read from the new page. Check that the link opens the product itself.');

    expect($shop->refresh()->current_price)->toBeNull();
});
