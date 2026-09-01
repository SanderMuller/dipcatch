<?php declare(strict_types=1);

use App\Livewire\Shops\AddShop;
use App\Livewire\Suggestions\ShopSuggestions;
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

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('SPAR')
        ->assertSee('PLUS')
        ->assertSee('dataset price € 3.69')
        ->assertSee('not trackable yet')
        ->assertSee('https://www.plus.nl/product/beemster-plus-1');
});

test('accepting a suggestion hands the url to the add-shop component', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('accept', 'https://www.spar.nl/beemster-spar-1/')
        ->assertDispatchedTo('shops.add-shop', 'suggest-shop', url: 'https://www.spar.nl/beemster-spar-1/');
});

test('the add-shop component probes a suggested url and shows the preview', function (): void {
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Demo Item',
            'offers' => [
                '@type' => 'Shop',
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
        ->assertSet('snapshot.price', '50.00');
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

test('it says so when the catalogue holds no match for this product', function (): void {
    seedRow('spar', 'Something else entirely', '1 l', '2.00', link: 'other-1');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSee('Also sold at')
        ->assertSee('No other shops found');
});

test('it stays silent when every chain is stale — that is not an answer about this product', function (): void {
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', refreshedAt: now()->subHours(97), link: 'beemster-spar-1/');

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSee('Also sold at')
        ->assertDontSee('No other shops found');
});

test('it stays silent when the catalogue itself is empty', function (): void {
    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSee('Also sold at')
        ->assertDontSee('No other shops found');
});

test('accepting several suggestions in a row hits the per-user probe budget', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/*' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $product = suggestionProduct();
    $this->actingAs($product->user()->sole());

    $component = Livewire::test(AddShop::class, ['product' => $product]);

    // ProbeShopUrl allows six probes per user per minute.
    foreach (range(1, 6) as $attempt) {
        $component->call('useSuggestion', "https://shop.example.com/p/{$attempt}");
    }

    $component->call('useSuggestion', 'https://shop.example.com/p/7')
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
                '@type' => 'Shop',
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
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertDontSeeLivewire('suggestions.shop-suggestions');
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
