<?php declare(strict_types=1);

use App\Jobs\DiscoverWebShops;
use App\Livewire\Products\AddProductWizard;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
});

/** Step 2 of the wizard, for a product of the signed-in account. */
function wizardOnShops(Product $product): Testable
{
    return Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 2])
        ->test(AddProductWizard::class);
}

it('lists the shops and offers the add-shop form, with its suggestions shown once', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '2.49']);
    $this->actingAs($user);

    wizardOnShops($product)
        ->assertSeeHtml('data-test="wizard-shop-list"')
        ->assertSee('ah.nl')
        ->assertSee('€2.49')
        ->assertSeeLivewire('shops.add-shop');

    $html = $this->get(route('app.products.create', ['product' => (string) $product->id, 'step' => 2]))->assertOk()->getContent();

    expect(substr_count((string) $html, 'wire:name="suggestions.shop-suggestions"'))->toBe(1);
});

it('shows the shop limit instead of the form on a free product with 4 shops', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    Shop::factory()->count(4)->for($product)->create();
    $this->actingAs($user);

    wizardOnShops($product)
        ->assertSeeHtml('data-test="shop-limit"')
        ->assertSee('This product is at its shop limit')
        ->assertDontSeeLivewire('shops.add-shop');
});

it('tells a product saved by hand what adding a shop does', function (bool $searchesWeb, string $copy): void {
    $user = User::factory()->create(['shop_checks' => $searchesWeb]);

    if ($searchesWeb) {
        subscribeUser($user);
    }

    $product = Product::factory()->for($user)->create();
    $this->actingAs($user);

    wizardOnShops($product)
        ->assertSeeHtml('data-test="wizard-no-shops"')
        ->assertSee($copy);
})->with([
    // Only an account with the AI shop check gets the web search.
    'Pro with AI help' => [true, 'Add one shop and we search the web for more.'],
    'free' => [false, 'Add the shops you buy it at, to compare their prices.'],
]);

describe('a product saved by hand gets its first shop', function (): void {
    beforeEach(function (): void {
        config()->set('services.serper.key', 'test-key');
        config()->set('services.typesafe.key', 'test-key');
        config()->set('dipcatch.web_discovery.enabled', true);
        config()->set('dipcatch.web_discovery.klarna_leads', false);
    });

    it('starts the web search', function (): void {
        $user = User::factory()->create(['shop_checks' => true]);
        subscribeUser($user);
        $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
        $this->actingAs($user);

        $wizard = wizardOnShops($product);
        Shop::factory()->for($product)->create();
        $wizard->dispatch('shop-added', offerId: 'x');

        Queue::assertPushed(DiscoverWebShops::class);
    });

    it('does not start a second search for a product that had one', function (): void {
        $user = User::factory()->create(['shop_checks' => true]);
        subscribeUser($user);
        $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
        Shop::factory()->for($product)->create();
        WebDiscovery::markQueued($product);
        $this->actingAs($user);

        wizardOnShops($product)->dispatch('shop-added', offerId: 'x');

        Queue::assertNotPushed(DiscoverWebShops::class);
    });
});

it('lists a shop the add-shop form just added', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $this->actingAs($user);

    $wizard = wizardOnShops($product)->assertDontSee('jumbo.com');
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1']);

    $wizard->dispatch('shop-added', offerId: 'x')
        ->assertSee('jumbo.com')
        ->assertDontSeeHtml('data-test="wizard-no-shops"');
});

it('goes on to the alerts with or without new shops', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $this->actingAs($user);

    wizardOnShops($product)
        ->call('goToStep', 3)
        ->assertSet('step', 3);
});
