<?php declare(strict_types=1);

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ShopDraft;
use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\DiscoverWebShops;
use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductShow;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Reported 2026-09-30: two web suggestions showed, adding one of them left
 * "Looking for more shops…" spinning and then an empty list. The added shop
 * came in another pack size, which changes the fingerprint and hides every
 * finding until it is checked again, and nothing re-checked it before the
 * nightly run.
 */
beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    Cache::flush();
    Queue::fake();
});

function requeueProduct(): Product
{
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);

    $product = Product::factory()->for($user)->create(['title' => 'Cavalor Hoof Aid 1 kg', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://tracked.test/p/1', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    $product->refresh();

    WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => 'https://junai.test/p/1',
        'url_hash' => hash('sha256', 'junai'),
        'host' => 'junai.test',
        'search_title' => 'Cavalor Hoof Aid',
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
    ]);
    WebDiscovery::mark($product, WebDiscoveryState::Done);

    return $product;
}

function addShopIn(Product $product, string $packSize): void
{
    app(AttachShop::class)($product, ShopDraft::fromSnapshot(
        ['price' => '19.95', 'currency' => 'EUR', 'in_stock' => true, 'title' => "Cavalor Hoof Aid {$packSize}", 'pack_size' => $packSize, 'pack_size_authoritative' => true],
        'https://dierapotheker.test/p/1',
        'jsonld',
    ));
}

it('re-checks the web suggestions at once when an added shop brings a new pack size', function (): void {
    $product = requeueProduct();

    addShopIn($product, '5 kg');

    Queue::assertPushed(DiscoverWebShops::class, fn (DiscoverWebShops $job): bool => $job->productId === (string) $product->id);
    expect(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Queued);
});

it('leaves the suggestions alone when the added shop sells the same pack', function (): void {
    $product = requeueProduct();

    addShopIn($product, '1 kg');

    Queue::assertNotPushed(DiscoverWebShops::class);
    expect(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Done);
});

it('re-checks after removing the only shop in a pack size, and tells the panel', function (): void {
    $product = requeueProduct();
    $other = Shop::factory()->for($product)->create(['url' => 'https://other.test/p/1', 'pack_quantity' => '5000.00', 'pack_unit' => 'g']);
    WebShopFinding::query()->update(['fingerprint' => WebShopFinding::fingerprintFor($product->refresh())]);
    $this->actingAs($product->user()->sole());

    Livewire::test(ProductShow::class, ['product' => $product])
        ->call('removeShop', $other->id)
        ->assertDispatched('shop-removed');

    Queue::assertPushed(DiscoverWebShops::class);
});

it('re-checks when a removed shop takes a barcode a finding was checked against', function (): void {
    $product = requeueProduct();
    $product->shops()->sole()->forceFill(['gtin' => '8711000000007'])->save();
    $other = Shop::factory()->for($product)->create(['url' => 'https://other.test/p/1', 'pack_quantity' => '1000.00', 'pack_unit' => 'g', 'gtin' => '8711000000014']);
    WebShopFinding::query()->update(['checked_gtins' => json_encode(['8711000000007', '8711000000014'])]);
    $this->actingAs($product->user()->sole());

    Livewire::test(ProductShow::class, ['product' => $product->refresh()])->call('removeShop', $other->id);

    Queue::assertPushed(DiscoverWebShops::class);
});

it('stays unique only while queued, so a change during a run queues the next one', function (): void {
    expect(new DiscoverWebShops('x'))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

it('re-checks after the product is renamed', function (): void {
    $product = requeueProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(EditProduct::class, ['product' => $product])
        ->set('title', 'Cavalor Hoof Aid Special 1 kg')
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(DiscoverWebShops::class);
});

it('polls again for the full window after a shop is added', function (): void {
    $product = requeueProduct();
    $this->actingAs($product->user()->sole());
    $panel = Livewire::test(ShopSuggestions::class, ['product' => $product]);

    $this->travel(10)->minutes();
    WebDiscovery::markQueued($product);

    $panel->dispatch('shop-added')->assertSeeHtml('wire:poll');
});
