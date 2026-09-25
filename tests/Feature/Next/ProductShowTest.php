<?php declare(strict_types=1);

use App\Enums\PackExclusion;
use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\Reference;
use App\Support\Favicon;
use App\Support\Numeric;
use App\Support\ProductMarkdown;
use App\Support\PromotionLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

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

it('links each tracked shop to a prefilled problem report', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1']);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Report a problem')
        ->assertSee(route('app.support', [
            'type' => 'shop_issue',
            'shop_url' => $shop->url,
        ]));
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
        'promotion_ends_at' => now()->addDays(2),
        'promotion_label' => '2 voor 4,00',
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product])
        ->assertSee('2 for €4.00')
        ->assertSeeText('Normal price: €2.85 each')
        ->assertSee('title="Regular price"', escape: false)
        ->assertSeeText('€1.90 /l')
        ->assertSee('Shop page:')
        ->assertSee('2 voor 4,00');

    expect(substr_count($component->html(), '2 for €4.00'))->toBe(2);
});

it('shows how far a deal puts the best value below the regular price', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '2.00');
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'promotion_ends_at' => now()->addDays(2),
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    // €1.33 /l on the deal against €1.90 /l for one bottle at the regular price.
    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeHtml('data-test="below-regular"')
        ->assertSeeInOrder(['below the regular price', '30%'])
        ->assertDontSeeHtml('data-test="no-drop"');
});

it('shows the drop an alert fired on in the price box', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '8.00');
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '8.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    $product->forceFill(['last_notified_price' => '8.00', 'last_notified_unit' => null])->save();

    PriceDropEvent::factory()->for($product)->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'reference_price' => '10.00',
        'comparison_unit' => null,
        'drop_pct' => '20.0',
        'fired_at' => now(),
    ]);

    $this->actingAs($user);

    // 8.00 a pack against the 10.00 the alert fired from.
    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeHtml('data-test="active-drop"')
        ->assertSeeInOrder(['Was €10.00', '20%'])
        ->assertDontSeeHtml('data-test="no-drop"');
});

it('says there is no drop when the best value has no regular price beside it', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '2.00');
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeText('€1.33 /l')
        ->assertDontSeeHtml('data-test="below-regular"')
        ->assertSeeHtml('data-test="no-drop"')
        ->assertSeeText('No drop right now');
});

it('warns about an ended bundle in the headline and shop row', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '2.00');
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/fanta',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'promotion_ends_at' => now()->subDay(),
        'promotion_label' => '2 voor 4,00',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product]);
    $deadline = PromotionLabel::short($shop);

    expect($deadline)->not->toBeNull()
        ->and(substr_count($component->html(), (string) $deadline))->toBe(2);
});

it('pauses and resumes', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, active: true);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->call('togglePaused');

    expect($product->fresh()?->active)->toBeFalse();
});

it('shows the tracking state on the pill that toggles it', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, active: true);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeText('Tracking')
        ->assertDontSeeText('Paused')
        ->call('togglePaused')
        ->assertSeeText('Paused')
        ->assertSeeText('Resume tracking');
});

it('shows whether the product has a public link', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeText('Not shared')
        ->call('generateShareLink')
        ->assertDontSeeText('Not shared')
        ->assertSeeText('Shared')
        ->call('stopSharing')
        ->assertSeeText('Not shared');
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

it('opens the add-shop disclosure when the page is asked to', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    // A string, as a real query string delivers it.
    Livewire::withQueryParams(['add-shop' => '1']);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product])->html());

    expect($html)->toContain('<details class="group w-full" open wire:ignore.self')
        // The suggestions panel hides itself against the same flag, so Alpine
        // must start in the open state too.
        ->toContain('x-data="{ addOpen: true }"');
});

it('leaves the add-shop disclosure closed without that request', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product])->html());

    expect($html)->toContain('x-data="{ addOpen: false }"')
        ->not->toContain('<details class="group w-full" open');
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

it('charts the best value per unit first, with the pack price one switch away', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create(['pack_quantity' => '800.00', 'pack_unit' => 'piece']);

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'best_value_shop_id' => $shop->id,
        'cheapest_price' => '21.99',
        'pack_quantity' => '800.00',
        'pack_unit' => 'piece',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee("x-data=\"{ basis: 'unit' }\"", escape: false)
        ->assertSee('Price over time')
        ->assertSeeInOrder(['Price per piece, at the shop that is the best value.', 'Price per pack, at the shop with the lowest price.'])
        ->assertSee('data-test="price-history-basis"', escape: false)
        ->assertSee('data-test="price-history-chart-unit"', escape: false)
        ->assertSee('data-test="price-history-chart-price"', escape: false);
});

it('opens on the pack price when the current price has no pack size', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '21.99',
        'pack_quantity' => '800.00',
        'pack_unit' => 'piece',
        'started_at' => now()->subDays(10),
        'ended_at' => now()->subDays(5),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '19.99',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    $this->actingAs($user);

    // The per-unit line would end in a gap today, so the switch starts on the pack price.
    livewire(ProductShow::class, ['product' => $product])
        ->assertSee("x-data=\"{ basis: 'price' }\"", escape: false)
        ->assertSee('data-test="price-history-basis"', escape: false);
});

it('opens on the pack price while the per-unit line is too short to read', function (): void {
    // A pack size that appeared at the last check: a month on the pack price,
    // then an hour with a size. The per-unit line would be one dot.
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '1.50',
        'started_at' => now()->subDays(25),
        'ended_at' => now()->subHour(),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '1.50',
        'best_value_shop_id' => $shop->id,
        'best_value_price' => '1.50',
        'pack_quantity' => '75.00',
        'pack_unit' => 'g',
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee("x-data=\"{ basis: 'price' }\"", escape: false)
        ->assertSee('data-test="price-history-basis"', escape: false);
});

it('charts the pack price alone when no pack size is known', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $shop = Shop::factory()->for($product)->create(['pack_quantity' => null, 'pack_unit' => null]);

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '2.19',
        'started_at' => now()->subDays(5),
        'ended_at' => null,
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee("x-data=\"{ basis: 'price' }\"", escape: false)
        ->assertSee('Price over time')
        ->assertSee('Price per pack, at the shop with the lowest price.')
        ->assertDontSee('at the shop that is the best value')
        ->assertDontSee('data-test="price-history-basis"', escape: false)
        ->assertDontSee('data-test="price-history-chart-unit"', escape: false);
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

it('links the alert threshold to the edit form', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Alerts')
        ->assertSeeHtml('data-test="edit-alert-threshold"')
        ->assertSeeHtml(route('app.products.edit', $product));
});

it('shows the category as a badge that links to the list filtered on it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Coffee & tea')
        ->assertSeeHtml(route('app.products.index', ['category' => 'food.coffee_tea']));
});

it('says a product has no category, and links to the others without one', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertDontSeeHtml('data-test="product-category-badge"')
        ->assertSeeText('No category')
        ->assertSeeHtml(route('app.products.index', ['category' => ProductList::NO_CATEGORY]));
});

it('shows why a shop carries no unit price, and marks an inherited size', function (): void {
    // Section 6 of the spec: a shop outside the unit comparison is present,
    // priced, and says which fact is missing — never silently absent.
    $user = User::factory()->create();
    $product = ownedProduct($user, title: 'Protein bars');

    foreach ([
        ['host' => 'ah.nl', 'price' => '22.00', 'quantity' => '660.00', 'unit' => 'g'],
        ['host' => 'jumbo.com', 'price' => '23.00', 'quantity' => '660.00', 'unit' => 'g'],
        ['host' => 'fitnesscandy.nl', 'price' => '18.00', 'quantity' => '12.00', 'unit' => 'piece'],
        ['host' => 'barebells.nl', 'price' => '21.00', 'quantity' => null, 'unit' => null],
    ] as $row) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $row['host'] . '/p/1'])
            ->forceFill([
                'currency' => 'EUR',
                'current_price' => $row['price'],
                'current_in_stock' => true,
                'pack_quantity' => $row['quantity'],
                'pack_unit' => $row['unit'],
            ])->save();
    }

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSee('fitnesscandy.nl')
        ->assertSee('Sold by the piece — no item size to compare')
        ->assertSee('barebells.nl')
        ->assertSee('estimated');
});

it('dates the price by when it was last read, not by the last attempt', function (): void {
    // `last_checked_at` is stamped by every attempt, a failed one included, so a
    // shop failing for a week showed "2 hours ago" beside a week-old price. The
    // price simply stopped moving and looked current while it did — the same
    // silent failure the unit comparison had to have removed from it.
    $user = User::factory()->create();
    $product = ownedProduct($user, title: 'Coffee beans');

    Shop::factory()->for($product)->create(['url' => 'https://failing.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR',
            'current_price' => '12.49',
            'last_success_at' => now()->subDays(8),
            'last_checked_at' => now()->subHours(2),
            'consecutive_failures' => 5,
        ])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSee('failing since')
        ->assertSee('1 week ago')
        ->assertDontSee('2 hours ago');
});

it('shows a healthy shop the age of its price', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, title: 'Coffee beans');

    Shop::factory()->for($product)->create(['url' => 'https://healthy.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR',
            'current_price' => '12.49',
            'last_success_at' => now()->subHours(3),
            'last_checked_at' => now()->subHours(3),
            'consecutive_failures' => 0,
        ])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSee('3 hours ago')
        ->assertDontSee('failing since');
});

it('says a shop has never been read rather than showing nothing', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, title: 'Coffee beans');

    Shop::factory()->for($product)->create(['url' => 'https://new.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR', 'current_price' => null,
            'last_success_at' => null, 'last_checked_at' => now(), 'consecutive_failures' => 2,
        ])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSee('never read');
});

/**
 * A product whose cheapest shop sells a small pack and whose best value
 * is a bigger pack elsewhere.
 */
function smallPackCheapest(User $user, string $bigPackPrice): Product
{
    $product = ownedProduct($user);
    $small = Shop::factory()->for($product)->create([
        'url' => 'https://dirk.nl/p/sticks', 'currency' => 'EUR', 'current_price' => '2.55',
        'pack_quantity' => '8.00', 'pack_unit' => 'piece',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/sticks', 'currency' => 'EUR', 'current_price' => $bigPackPrice,
        'pack_quantity' => '30.00', 'pack_unit' => 'piece',
    ]);
    $product->forceFill(['cheapest_shop_id' => $small->id, 'cheapest_price' => '2.55'])->save();

    return $product->refresh();
}

it('notes how much more per unit the lowest pack price costs', function (): void {
    $user = User::factory()->create();
    // €0.32 a piece against €0.22 a piece: 45% more.
    $product = smallPackCheapest($user, '6.60');

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        // A note beside the best value, not a warning: the headline already names it.
        ->assertSeeHtml('data-test="lowest-price-note"')
        ->assertDontSeeHtml('data-test="unit-price-warning"')
        ->assertSee('45% more per piece than the best value')
        ->assertSee('jumbo.com');
});

it('notes a small gap the same way', function (): void {
    $user = User::factory()->create();
    // €0.32 a piece against €0.30 a piece: about 6% more.
    $product = smallPackCheapest($user, '9.00');

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSeeHtml('data-test="lowest-price-note"')
        ->assertSee('6% more per piece than the best value');
});

it('does not warn when the best price is also the best value', function (): void {
    $user = User::factory()->create();
    // €0.32 a piece against €0.40 a piece: the small pack wins both.
    $product = smallPackCheapest($user, '12.00');

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->assertDontSeeHtml('data-test="lowest-price-note"');
});

it('lists the tracked shops cheapest per unit first, then the shops outside the comparison', function (): void {
    // The vissticks shape: dirk has the lowest pack price and the highest price per kilo.
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1', 'current_price' => '2.55', 'pack_quantity' => '240.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '6.15', 'pack_quantity' => '840.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '6.59', 'pack_quantity' => '30.00', 'pack_unit' => 'piece']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product->refresh()])->html());
    preg_match_all('/data-test="shop-price-cell"[^>]*>(.*?)<\/td>/', $html, $cells);
    $prices = array_map(fn (string $cell): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($cell))), $cells[1]);

    expect($prices)->toHaveCount(3)
        ->and($prices[0])->toStartWith('€7.32 /kg')
        ->and($prices[1])->toStartWith('€10.62 /kg')
        ->and($prices[2])->toContain('€6.59 for 30 pieces');
});

it('leads with the pack price when no shop states a pack size', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '11.95');
    $shop = Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/1', 'current_price' => '11.95', 'pack_quantity' => null, 'pack_unit' => null]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    $tiles = summaryTiles(livewire(ProductShow::class, ['product' => $product->refresh()])->html());

    expect($tiles)->toContain('Best price now')
        ->toContain('€11.95')
        ->not->toContain('Best value')
        ->not->toContain('data-test="pack-line"');
});

it('leads each shop row with its price per unit, and an excluded shop with its reason', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '4.99', 'pack_quantity' => '660.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '5.49', 'pack_quantity' => '660.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://fitnesscandy.nl/p/1', 'current_price' => '3.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product->refresh()])->html());
    preg_match_all('/data-test="shop-price-cell"[^>]*>(.*?)<\/td>/', $html, $cells);
    $text = array_map(fn (string $cell): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($cell))), $cells[1]);

    expect($html)->toContain('Price per kilo')
        ->and(collect($text)->first(fn (string $cell): bool => str_contains($cell, '€4.99')))->toStartWith('€7.56 /kg')->toContain('Best value')->toContain('€4.99 for 660 g')
        // Excluded from the per-kilo comparison: its reason, then its own pack.
        ->and(collect($text)->first(fn (string $cell): bool => str_contains($cell, '€3.00')))
        ->toContain(PackExclusion::SoldByThePiece->label())
        ->toContain('€3.00 for 12 pieces')
        ->not->toContain('/piece');
});

it('gives each tracked shop its pack size, and a dash when none is known', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '4.99', 'pack_quantity' => '660.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/1', 'current_price' => '11.95', 'pack_quantity' => null, 'pack_unit' => null]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product->refresh()])->html());
    preg_match_all('/data-test="shop-pack-size"[^>]*>(.*?)<\/td>/', $html, $cells);
    $sizes = array_map(fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);

    expect($html)->toContain('Pack size')
        ->and($sizes)->toContain('660 g')
        ->and(collect($sizes)->first(fn (string $size): bool => $size !== '660 g'))->toContain('Unknown');
});

it('marks the best-value shop in the comparison table', function (): void {
    // The vissticks shape: dirk has the lowest pack price, jumbo the best value.
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1', 'current_price' => '2.55', 'pack_quantity' => '240.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '6.15', 'pack_quantity' => '840.00', 'pack_unit' => 'g']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product->refresh()])->html());
    preg_match_all('/data-test="shop-price-cell"[^>]*>(.*?)<\/td>/', $html, $cells);
    $marked = array_values(array_filter($cells[1], fn (string $cell): bool => str_contains($cell, 'data-test="best-value-chip"')));

    expect($marked)->toHaveCount(1)
        ->and(strip_tags($marked[0]))->toContain('€7.32 /kg');
});

it('marks no best-value shop when there is only one shop', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '6.15', 'pack_quantity' => '840.00', 'pack_unit' => 'g']);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])->assertDontSeeHtml('data-test="best-value-chip"');
});

it('labels the last alert on the price chart without a hover', function (bool $alerted): void {
    $user = User::factory()->create();
    $product = ownedProduct($user, cheapestPrice: '85.00');
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    if ($alerted) {
        PriceDropEvent::factory()->for($product)->create([
            'user_id' => $user->id,
            'fired_at' => now()->subDays(2),
            'new_price' => '85.00',
        ]);
    }

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product]);

    if ($alerted) {
        $component->assertSeeHtml('data-test="notified-callout-price"')->assertSeeInOrder(['data-test="notified-callout-price"', 'Notified', '€85.00'], escape: false);
    } else {
        $component->assertDontSeeHtml('data-test="notified-callout-price"');
    }
})->with([
    'after an alert' => [true],
    'before any alert' => [false],
]);

it('marks stock with an icon in the stock column, named for screen readers', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '4.99', 'current_in_stock' => false]);

    $this->actingAs($user);

    $html = (string) preg_replace('/\s+/', ' ', livewire(ProductShow::class, ['product' => $product->refresh()])->html());
    preg_match('/data-test="shop-stock-cell"[^>]*>(.*?)<\/td>/', $html, $cell);

    expect($cell[1] ?? '')->toContain('<svg')
        ->toContain('<span class="sr-only">Out of stock</span>')
        ->not->toContain('data-flux-badge');
});

it('explains the unit price, the pack price and the read time on hover', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1',
        'current_price' => '4.99',
        'pack_quantity' => '660.00',
        'pack_unit' => 'g',
        'last_success_at' => CarbonImmutable::parse('2026-09-23 10:15', 'UTC'),
    ]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeText('Price per kilo: the pack price divided by the pack size, so packs of different sizes compare fairly.')
        ->assertSeeText('Pack price: what ah.nl charges for 660 g.')
        ->assertSeeText('Price last read 23 Sep 2026, 12:15.');
});

it('shows every alert rule the product has', function (): void {
    $user = User::factory()->create();
    $product = smallPackCheapest($user, '6.60');
    $product->forceFill(['target_price' => null, 'drop_threshold_pct' => '10.00', 'drop_threshold_abs' => null, 'unit_price_target' => '0.2000'])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeInOrder(['€0.2000 /piece', 'when a price reaches it', 'or 10% drop'])
        ->assertDontSee('Any drop');
});

it('says any drop when the product has no alert rule and no price yet', function (): void {
    $user = User::factory()->create();
    $product = ownedProduct($user);
    $product->forceFill(['target_price' => null, 'drop_threshold_pct' => null, 'drop_threshold_abs' => null, 'unit_price_target' => null])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])->assertSee('Any drop');
});

it('says below for a second price target too', function (): void {
    $user = User::factory()->create();
    $product = smallPackCheapest($user, '6.60');
    $product->forceFill(['target_price' => '2.00', 'unit_price_target' => '0.2000', 'drop_threshold_pct' => null, 'drop_threshold_abs' => null])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeInOrder(['€2.00', 'when a price reaches it', 'or €0.2000 /piece or less']);
});

it('names the default drop thresholds when the product has none of its own', function (): void {
    $user = User::factory()->create();
    // A €20 reference sits in the <25 tier: 15% or €3.00.
    $product = ownedProduct($user, cheapestPrice: '20.00');
    $product->forceFill(['target_price' => null, 'unit_price_target' => null, 'drop_threshold_pct' => null, 'drop_threshold_abs' => null])->save();
    ProductCheapestHistory::query()->create(['product_id' => $product->id, 'cheapest_price' => '20.00', 'started_at' => now()->subDays(10)]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeInOrder(['15% drop (default)', 'or €3.00 drop (default)'])
        ->assertDontSee('Any drop');
});

it('names the same default drop the drop check uses on a per-unit reference', function (): void {
    $user = User::factory()->create();
    // €7 for 250 g: €28 a kilo. The pack price and the kilo price sit in
    // different tiers, which is where a guess from the pack price goes wrong.
    $product = ownedProduct($user, cheapestPrice: '7.00');
    $shop = Shop::factory()->for($product)->create(['currency' => 'EUR', 'current_price' => '7.00', 'pack_quantity' => '250.00', 'pack_unit' => 'g']);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'target_price' => null, 'unit_price_target' => null, 'drop_threshold_pct' => null, 'drop_threshold_abs' => null])->save();
    ProductCheapestHistory::query()->create([
        'product_id' => $product->id, 'cheapest_shop_id' => $shop->id, 'cheapest_price' => '7.00',
        'pack_quantity' => '250.00', 'pack_unit' => 'g', 'started_at' => now()->subDays(10),
    ]);
    $product->refresh();

    $reference = app(Reference::class)->compute($product);

    if ($reference === null) {
        throw new RuntimeException('The product needs a reference for the drop check to compare with.');
    }

    $outcome = app(DropEvaluator::class)->evaluate($product, '6.00', $reference);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->assertSee(Numeric::trimmed((string) $outcome->thresholdPct) . '% drop (default)');
});

it('shows no unit figure from a shop\'s own size when the product compares none', function (): void {
    // The sized shop is paused, so it does not vote, and nothing resolves a unit.
    $user = User::factory()->create();
    $product = ownedProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/1', 'current_price' => '11.95', 'pack_quantity' => null, 'pack_unit' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '12.49', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'active' => false]);
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSee('€12.49')
        ->assertDontSee('/kg');

    expect(ProductMarkdown::of($product->refresh()))->not->toContain('/kg');
});
