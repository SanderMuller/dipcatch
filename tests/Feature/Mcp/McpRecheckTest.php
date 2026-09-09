<?php declare(strict_types=1);

use App\Actions\Shops\ProbeBudget;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\RecheckTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A stored value is only as fresh as its last scheduled check. When a fixed
 * adapter changes what a page means, the tracked row keeps the old answer
 * until then, and removing the shop to re-add it destroys its history.
 */
beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

function soldOutPage(string $price = '19.95'): array
{
    $json = (string) json_encode([
        '@type' => 'Product',
        'name' => 'Sanimed Skin Sensitive Kat',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/OutOfStock'],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/*' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

test('a recheck replaces a stale row with what the page says now', function (): void {
    Http::fake(soldOutPage());

    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'currency' => 'EUR',
        'current_price' => '24.95',
        'current_in_stock' => true,
        'adapter_key' => 'jsonld',
    ]);

    DipCatchServer::actingAs($me)
        ->tool(RecheckTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee('"rechecked":1');

    $shop->refresh();

    expect($shop->current_in_stock)->toBeFalse()
        ->and((string) $shop->current_price)->toBe('19.95');
});

test('a recheck can name one shop', function (): void {
    Http::fake(soldOutPage());

    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);
    $first = Shop::factory()->for($product)->create(['url' => 'https://shop.test/p/1', 'currency' => 'EUR', 'current_in_stock' => true, 'adapter_key' => 'jsonld']);
    $second = Shop::factory()->for($product)->create(['url' => 'https://shop.test/p/2', 'currency' => 'EUR', 'current_in_stock' => true, 'adapter_key' => 'jsonld']);

    DipCatchServer::actingAs($me)
        ->tool(RecheckTool::class, ['product_id' => (string) $product->id, 'shop_id' => (string) $first->id])
        ->assertOk();

    expect($first->refresh()->current_in_stock)->toBeFalse()
        ->and($second->refresh()->current_in_stock)->toBeTrue();
});

test('a recheck stops at the page budget and says how much is left to do', function (): void {
    Http::fake(soldOutPage());

    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);

    foreach (range(1, ProbeBudget::PER_MINUTE + 2) as $i) {
        Shop::factory()->for($product)->create([
            'url' => "https://shop.test/p/{$i}",
            'currency' => 'EUR',
            'adapter_key' => 'jsonld',
        ]);
    }

    DipCatchServer::actingAs($me)
        ->tool(RecheckTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee('"rechecked":' . ProbeBudget::PER_MINUTE)
        ->assertSee('"skipped":2')
        ->assertSee('The page budget ran out');
});

test('a recheck will not touch another users product', function (): void {
    $theirs = Product::factory()->create();

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(RecheckTool::class, ['product_id' => (string) $theirs->id])
        ->assertHasErrors();
});
