<?php declare(strict_types=1);

use App\Jobs\ReadKlarnaLeads;
use App\Livewire\Products\AddProductWizard;
use App\Livewire\Shops\AddShop;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function pastedKlarnaUrl(): string
{
    return 'https://www.klarna.com/nl/shopping/pl/cl456/3202266158/Huisdieren/Hill-s-10kg-Young-Adult-Sterilised-met-Eend-Science/';
}

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    config()->set('dipcatch.web_discovery.klarna_leads', true);
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake([ReadKlarnaLeads::class]);
});

function fakePastedKlarna(int $status = 200): void
{
    Http::fake([
        'https://www.klarna.com/robots.txt' => Http::response('', 404),
        pastedKlarnaUrl() => Http::response($status === 200 ? File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')) : '', $status, ['Content-Type' => 'text/html']),
    ]);
}

/** A Pro product with a shop; by default shop checks on and in euros. */
function pasteProduct(bool $shopChecks = true, string $currency = 'EUR'): Product
{
    $user = User::factory()->create(['shop_checks' => $shopChecks]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => $currency]);
    Shop::factory()->for($product)->create(['url' => 'https://www.zooplus.nl/shop/hills/939943']);

    return $product->refresh();
}

test('a pasted Klarna page is refused, lists its shops, and has them looked up', function (): void {
    fakePastedKlarna();
    $product = pasteProduct();
    $this->actingAs($product->user()->sole());

    $html = Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', pastedKlarnaUrl())
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertSee('Klarna lists these shops:')
        ->assertSee('DipCatch is looking these shops up. Matches show under shop suggestions.')
        ->assertDontSee('clk.klarna.com')
        ->html();

    // In stock first, then cheapest.
    $positions = array_map(static fn (string $text): int|false => strpos($html, $text), ['€76.05', '€81.99', '€162.99', '€85.69', '€167.95']);
    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(array_values(Arr::sort($positions)));

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_url)->toBe(pastedKlarnaUrl());
    Queue::assertPushed(ReadKlarnaLeads::class, 1);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'clk.klarna.com'));
});

test('the create-product form lists the shops without a lookup', function (): void {
    fakePastedKlarna();
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', pastedKlarnaUrl())
        ->call('probe')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertSee('Klarna lists these shops:')
        ->assertDontSee('DipCatch is looking these shops up.');

    Queue::assertNothingPushed();
});

test('a Klarna page that is not a product page is refused without a fetch', function (): void {
    Http::fake();
    $product = pasteProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://www.klarna.com/nl/shopping/results/?q=hills')
        ->call('probe')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertDontSee('Klarna lists these shops:');

    Http::assertNothingSent();
});

test('a Klarna page that cannot be read gives the plain refusal', function (): void {
    fakePastedKlarna(403);
    $product = pasteProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', pastedKlarnaUrl())
        ->call('probe')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertSee('This is a comparison site, not a shop.')
        ->assertDontSee('Klarna lists these shops:');

    Queue::assertNothingPushed();
});

test('the lookup is refused when web discovery does not run for the product', function (Closure $setUp): void {
    $product = pasteProduct();
    $setUp($product);

    expect(app(WebShopDiscovery::class)->useKlarnaPage($product->refresh(), pastedKlarnaUrl()))->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'discovery off' => [fn (): null => config()->set('dipcatch.web_discovery.enabled', false)],
    'no search key' => [fn (): null => config()->set('services.serper.key', '')],
    'Klarna steps off' => [fn (): null => config()->set('dipcatch.web_discovery.klarna_leads', false)],
    'not in euros' => [fn (Product $product): Product => tap($product, fn (Product $p) => $p->forceFill(['currency' => 'GBP'])->save())],
    'owner without shop checks' => [fn (Product $product): Product => tap($product, fn (Product $p) => $p->user()->sole()->forceFill(['shop_checks' => false])->save())],
]);

test('an assistant pasting a Klarna page gets the shop list as text', function (): void {
    fakePastedKlarna();
    $product = pasteProduct();

    DipCatchServer::actingAs($product->user()->sole())
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => pastedKlarnaUrl()])
        ->assertSee('This is a comparison site, not a shop.')
        ->assertSee('Klarna lists these shops:')
        ->assertSee('- Medpets (medpets.nl): €76.05 — Hill\'s Science Plan Sterilised Cat - Adult - Eend - 10 kg');
});

test('an account that pasted its daily share of Klarna pages gets the list without a lookup', function (): void {
    config()->set('dipcatch.web_discovery.klarna_pastes_per_day', 1);
    $second = 'https://www.klarna.com/nl/shopping/pl/cl456/111/Huisdieren/Hill-s-Kitten/';
    $page = File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html'));
    Http::fake([
        'https://www.klarna.com/robots.txt' => Http::response('', 404),
        pastedKlarnaUrl() => Http::response($page, 200, ['Content-Type' => 'text/html']),
        $second => Http::response($page, 200, ['Content-Type' => 'text/html']),
    ]);
    $product = pasteProduct();
    $this->actingAs($product->user()->sole());
    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', pastedKlarnaUrl())
        ->call('probe')
        ->assertSee('DipCatch is looking these shops up.');

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', $second)
        ->call('probe')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertSee('Klarna lists these shops:')
        ->assertDontSee('DipCatch is looking these shops up.');

    expect(app(WebShopDiscovery::class)->useKlarnaPage($product->refresh(), $second))->toBeFalse()
        ->and(WebDiscovery::query()->findOrFail($product->id)->klarna_url)->toBe(pastedKlarnaUrl());
    Queue::assertPushed(ReadKlarnaLeads::class, 1);
});

test('the same Klarna page pasted again while it is looked up starts no new lookup', function (): void {
    fakePastedKlarna();
    $product = pasteProduct();
    $this->actingAs($product->user()->sole());
    $component = Livewire::test(AddShop::class, ['product' => $product])->set('url', pastedKlarnaUrl());
    $component->call('probe');
    $generation = WebDiscovery::query()->findOrFail($product->id)->klarna_generation;

    $component->call('probe')->assertSee('DipCatch is looking these shops up.');

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_generation)->toBe($generation);
    Queue::assertPushed(ReadKlarnaLeads::class, 1);
});
