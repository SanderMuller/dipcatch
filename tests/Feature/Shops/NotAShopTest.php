<?php declare(strict_types=1);

use App\Actions\Shops\KeepShopAsLink;
use App\Console\Commands\RetryReferenceShopsCommand;
use App\Enums\ProbeFailure;
use App\Enums\ShopKind;
use App\Jobs\CheckShopPrice;
use App\Livewire\Shops\AddShop;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopDiscovery\WebResultFilter;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

test('a pasted comparison-site link is refused with a message and no keep-as-link', function (string $url): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', $url)
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'not_a_shop')
        ->assertSee('This is a comparison site, not a shop. Paste the link of the shop that sells it.')
        ->assertDontSee('Keep as a link');

    Http::assertNothingSent();
    expect(ProbeFailure::NotAShop->isWorthKeepingAsLink())->toBeFalse();
})->with([
    'bcc.nl' => 'https://www.bcc.nl/product/siemens-cm776gmb1f',
    'maxict.nl' => 'https://maxict.nl/product/canon-selphy-cp1500-wit',
    'tweakers.net' => 'https://tweakers.net/pricewatch/1234/some-product.html',
]);

test('an assistant pasting a comparison-site link gets the same answer', function (): void {
    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR']);

    DipCatchServer::actingAs($product->user()->sole())
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://www.bcc.nl/product/siemens-cm776gmb1f'])
        ->assertSee('This is a comparison site, not a shop.');

    Http::assertNothingSent();
    expect($product->shops()->count())->toBe(0);
});

test('web discovery skips the same hosts from the shared list', function (): void {
    expect(WebResultFilter::isNotAShop('www.bcc.nl'))->toBeTrue()
        ->and(WebResultFilter::isNotAShop('maxict.nl'))->toBeTrue()
        ->and(WebResultFilter::isNotAShop('makro.nl'))->toBeTrue()
        ->and(WebResultFilter::isNotAShop('expert.nl'))->toBeFalse();
});

test('a shop already tracked on a listed host becomes a link at its next check', function (): void {
    Http::fake();
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://www.bcc.nl/product/1', 'current_price' => '1699.00']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    Http::assertNothingSent();
    expect($shop->refresh()->kind)->toBe(ShopKind::Reference)
        ->and($shop->unreadable_reason)->toBe(ProbeFailure::NotAShop->value)
        ->and($shop->current_price)->toBeNull();
});

test('a link kept on a listed host stays a link on its weekly retry', function (): void {
    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    app(KeepShopAsLink::class)($product, 'https://www.bcc.nl/product/1', 'blocked');

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    expect($product->refresh()->shops->sole()->kind)->toBe(ShopKind::Reference);
    Http::assertNothingSent();
});
