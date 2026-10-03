<?php declare(strict_types=1);

use App\Actions\Shops\ProbeBudget;
use App\Enums\ShopKind;
use App\Livewire\Shops\AddShop;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\CheckjebonChain;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionDismissal;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    seedChains();
});

function suggestionProduct(?User $owner = null): Product
{
    $product = Product::factory()->for($owner ?? User::factory()->create())->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://kaasshop.test/p/1',
        'pack_quantity' => '150.00',
        'pack_unit' => 'g',
    ]);

    return $product->refresh();
}

test('it lists a trackable and an untrackable suggestion, each labelled', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');
    seedRow('plus', 'Beemster Extra belegen 48+ plakken', '150 g', '3.39', link: 'beemster-plus-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    $component = Livewire::test(ShopSuggestions::class, ['product' => $product]);

    // Per kilo first, the dataset's pack price beside it, through the money formatter.
    expect(preg_replace('/\s+/', ' ', strip_tags($component->html())))->toContain('€24.60 /kg · dataset price €3.69 for 150 g');

    $component
        ->assertSee('SPAR')
        ->assertSee('PLUS')
        ->assertSee('not trackable yet')
        ->assertSee('https://www.plus.nl/product/beemster-plus-1')
        // Both rows link out: a shopper may want to see a product before
        // tracking it, not only when tracking is impossible.
        ->assertSee('https://www.spar.nl/beemster-spar-1/');
});

test('accepting a suggestion hands the url to the add-shop component', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('accept', 'https://www.spar.nl/beemster-spar-1/')
        ->assertDispatchedTo('shops.add-shop', 'suggest-shop', url: 'https://www.spar.nl/beemster-spar-1/');
});

test('the add and hide buttons render a callable wire:click, not an uncompiled directive', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSeeHtml('@js(')
        ->assertSeeHtml('accept(\'https:\/\/www.spar.nl\/beemster-spar-1\/\')')
        ->assertSeeHtml('dismiss(\'spar\', \'beemster-spar-1\/\')');
});

test('the add-shop component probes a suggested url and shows the preview', function (): void {
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Demo Item',
            'offers' => [
                '@type' => 'Offer',
                'price' => '50.00',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->call('useSuggestion', 'https://shop.example.com/p/1')
        ->assertSet('state', 'preview')
        ->assertSet('snapshot.price', '50.00')
        // Tells the suggestion's button to stop saying "Adding…".
        ->assertDispatched('shop-probe-finished');
});

test('the add button says it is adding the moment it is clicked, and the form says it is checking', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSeeHtml('x-on:click="$el.dataset.adding = \'true\'"')
        ->assertSeeHtml('Adding…');

    Livewire::test(AddShop::class, ['product' => $product])
        ->assertSeeHtml('data-test="add-shop-checking"')
        ->assertSee("Checking the price on the shop's page.");
});

test('dismissing a suggestion persists and removes it from the list', function (): void {
    $row = seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('SPAR')
        ->call('dismiss', 'spar', $row->external_id)
        ->assertDontSee('SPAR');

    expect(ShopSuggestionDismissal::query()->count())->toBe(1);
});

test('an untrackable suggestion is kept as a link and leaves the list', function (): void {
    $row = seedRow('plus', 'Beemster Extra belegen 48+ plakken', '150 g', '3.39', link: 'beemster-plus-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSeeHtml('data-test="suggestion-keep-link"')
        ->call('keepAsLink', 'plus', $row->external_id)
        ->assertDispatched('shop-added')
        ->assertDontSee('not trackable yet');

    $shop = $product->shops()->where('kind', ShopKind::Reference->value)->sole();

    expect($shop->url)->toBe('https://www.plus.nl/product/beemster-plus-1')
        ->and($shop->current_price)->toBeNull();
});

test('keeping as a link refuses a trackable suggestion and an unlisted one', function (string $chain, string $externalId): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('keepAsLink', $chain, $externalId)
        ->assertNotDispatched('shop-added');

    expect($product->shops()->count())->toBe(1);
})->with([
    'trackable' => ['spar', 'beemster-spar-1/'],
    'not listed' => ['plus', 'made-up-id'],
]);

test('keeping as a link counts against the shop limit', function (): void {
    config()->set('plans.free.max_shops_per_product', 1);
    $row = seedRow('plus', 'Beemster Extra belegen 48+ plakken', '150 g', '3.39', link: 'beemster-plus-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('keepAsLink', 'plus', $row->external_id)
        ->assertNotDispatched('shop-added');

    expect($product->shops()->count())->toBe(1);
});

test('it says so when the catalogue holds no match for this product', function (): void {
    seedRow('spar', 'Something else entirely', '1 l', '2.00', link: 'other-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product, 'explainEmpty' => true])
        ->assertDontSee('Also sold at')
        ->assertSee('No shop suggestions for this product');
});

test('the copy under the section heading stays silent when nothing matches; the add-shop form says it', function (): void {
    seedRow('spar', 'Something else entirely', '1 l', '2.00', link: 'other-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSee('No shop suggestions for this product');
    Livewire::test(AddShop::class, ['product' => $product])
        ->assertSee('No shop suggestions for this product');
});

test('it stays silent when every chain is stale — that is not an answer about this product', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', refreshedAt: now()->subHours(97), link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product, 'explainEmpty' => true])
        ->assertDontSee('Also sold at')
        ->assertDontSee('No shop suggestions for this product');
});

test('it stays silent when the catalogue itself is empty', function (): void {
    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product, 'explainEmpty' => true])
        ->assertDontSee('Also sold at')
        ->assertDontSee('No shop suggestions for this product');
});

test('accepting several suggestions in a row hits the per-user probe budget', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/*' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    $component = Livewire::test(AddShop::class, ['product' => $product]);

    foreach (range(1, ProbeBudget::PER_MINUTE) as $attempt) {
        $component->call('useSuggestion', "https://shop.example.com/p/{$attempt}");
    }

    $component->call('useSuggestion', 'https://shop.example.com/p/over')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'probe_rate_limited');
});

test('another user cannot mount the component for a product they do not own', function (): void {
    $product = suggestionProduct();
    $this->actingAs(User::factory()->create());

    Livewire::test(ShopSuggestions::class, ['product' => $product])->assertForbidden();
});

test('another user cannot dismiss or accept by tampering with the product id', function (string $method, array $args): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $owner = User::factory()->create();
    $product = suggestionProduct($owner);

    $this->actingAs($owner);
    $component = Livewire::test(ShopSuggestions::class, ['product' => $product]);

    $this->actingAs(User::factory()->create());

    $component->call($method, ...$args)->assertForbidden();
})->with([
    ['dismiss', ['spar', 'beemster-spar-1/']],
    ['accept', ['https://www.spar.nl/beemster-spar-1/']],
    ['keepAsLink', ['plus', 'beemster-plus-1']],
    ['dismissWeb', [1]],
]);

test('add-shop refuses a probe or confirm for another user\'s product', function (string $method): void {
    $owner = User::factory()->create();
    $product = suggestionProduct($owner);

    $this->actingAs($owner);
    $component = Livewire::test(AddShop::class, ['product' => $product])->set('url', 'https://shop.example.com/p/1');

    $this->actingAs(User::factory()->create());

    $component->call($method)->assertForbidden();
})->with(['probe', 'probeWithSelectors', 'selectVariant', 'confirm', 'showManualSelector', 'cancel']);

test('the add-shop disclosure lists suggestions while idle and hides them during a preview', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Demo Item',
            'offers' => [
                '@type' => 'Offer',
                'price' => '50.00',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->assertSeeLivewire('suggestions.shop-suggestions')
        ->assertSeeHtml('x-data="{ open: true }"')
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertDontSeeLivewire('suggestions.shop-suggestions');
});

test('the suggestions list stays expanded once a shop is added', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Demo Item',
            'offers' => [
                '@type' => 'Offer',
                'price' => '50.00',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->assertSet('expandSuggestions', true)
        ->assertSeeHtml('x-data="{ open: true }"')
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->call('confirm')
        ->assertSet('state', 'idle')
        ->assertSeeHtml('x-data="{ open: true }"')
        // The product page refreshes its shops on this event, so dropping it
        // would leave that page stale with nothing else to catch it.
        ->assertDispatched('shop-added');
});

test('accepting also asks the collapsed add-shop disclosure to open', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('accept', 'https://www.spar.nl/beemster-spar-1/')
        ->assertDispatched('open-add-shop');
});

test('another user cannot mount the add-shop component for a product they do not own', function (): void {
    $product = suggestionProduct();
    $this->actingAs(User::factory()->create());

    Livewire::test(AddShop::class, ['product' => $product])->assertForbidden();
});

test('dismissing tells every suggestions instance on the page to refresh', function (): void {
    $row = seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('dismiss', 'spar', $row->external_id)
        ->assertDispatched('shop-suggestions-changed');
});

test('the link field and the suggestions hide while a shop\'s page is read', function (): void {
    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    $html = Livewire::test(AddShop::class, ['product' => $product])->html();

    // Two wrappers: the suggestions, and the form. Not on the form itself,
    // where Livewire would scope it to the form's own submit.
    expect(substr_count($html, '<div wire:loading.remove.block>'))->toBe(2)
        ->and($html)->not->toContain('<form wire:submit.prevent="probe" wire:loading');
});

test('labels a bol.com suggestion with bol.com\'s own price, not the dataset\'s', function (): void {
    CheckjebonChain::query()->create(['chain' => 'bol', 'label' => 'bol.com', 'base_url' => 'https://www.bol.com/nl/nl/p/', 'refreshed_at' => now()]);
    seedRow('bol', 'Beemster Extra belegen 48+ plakken', '150 g', '3.59', link: 'beemster/9300000001/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    $text = (string) preg_replace('/\s+/', ' ', strip_tags(Livewire::test(ShopSuggestions::class, ['product' => $product])->html()));

    expect($text)->toContain('bol.com price €3.59 for 150 g')
        ->not->toContain('dataset price €3.59');
});
