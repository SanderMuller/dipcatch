<?php declare(strict_types=1);

use App\Livewire\AiFeaturePrompt;
use App\Livewire\Shops\AddShop;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.shop_checks.warn_below', 0.5);
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

/**
 * A Pro account that switched the shop check on, with one tracked shop.
 */
function productWithShopChecks(bool $optedIn = true, bool $pro = true): Product
{
    $user = User::factory()->create(['shop_checks' => $optedIn]);

    if ($pro) {
        subscribeUser($user);
    }

    $product = Product::factory()->for($user)->create(['title' => 'Whiskas Adult Kip 12 x 85 g', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['host' => 'zooplus.nl', 'pack_quantity' => 1020, 'pack_unit' => 'g', 'gtin' => '8711000530450']);

    return $product;
}

/**
 * @return array<string, mixed>
 */
function sameProductAnswer(float $chance): array
{
    return ['model' => 'jev-latest', 'answers' => ['draft' => ['type' => 'noul', 'noul' => $chance]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 10]];
}

test('the add-shop preview warns when Jev doubts the page sells the same product and pack', function (): void {
    Http::fake(fakeJsonLdOffer(name: 'Whiskas Adult Zalm 4 x 85 g') + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.12))]);
    $product = productWithShopChecks();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('sameProductChance', 0.12)
        ->assertSeeHtml('data-test="same-product-warning"')
        ->assertDontSeeHtml('data-test="signal-ai"')
        ->call('cancel')
        ->assertSet('sameProductChance', null)
        ->assertDontSeeHtml('data-test="same-product-warning"');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== TypeSafeClient::ENDPOINT) {
            return false;
        }

        $question = $request->data()['questions']['draft'];

        expect($request->data()['state']['tracked_pack_sizes'])->toBe(['1020 g'])
            ->and($question['type'])->toBe('noul')
            ->and($question['instructions']['candidate'])->toMatchArray(['shop' => 'shop.example.com', 'title' => 'Whiskas Adult Zalm 4 x 85 g']);

        return true;
    });
});

test('the preview stays quiet when Jev agrees, and confirming still works either way', function (): void {
    Http::fake(fakeJsonLdOffer(name: 'Whiskas Adult Kip 12 x 85 g') + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.93))]);
    $product = productWithShopChecks();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertDontSeeHtml('data-test="same-product-warning"')
        ->call('confirm')
        ->assertSet('state', 'idle');

    expect($product->shops()->count())->toBe(2);
});

test('nothing is sent without the opt-in, on the free plan, or for a product with no shop yet', function (string $case): void {
    Http::fake(fakeJsonLdOffer() + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.1))]);

    $product = match ($case) {
        'opted out' => productWithShopChecks(optedIn: false),
        'free plan' => productWithShopChecks(pro: false),
        'no shop yet' => tap(productWithShopChecks(), fn (Product $p) => $p->shops()->delete()),
        default => throw new InvalidArgumentException($case),
    };
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product->fresh()])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('sameProductChance', null)
        ->assertDontSeeHtml('data-test="signal-ai"');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT);
})->with(['opted out', 'free plan', 'no shop yet']);

test('a shared barcode settles the check without a paid call', function (): void {
    $product = productWithShopChecks();
    $json = json_encode(['@type' => 'Product', 'name' => 'Whiskas multipack', 'gtin13' => '8711000530450', 'offers' => ['@type' => 'Offer', 'price' => '4.99', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock']], JSON_THROW_ON_ERROR);
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
        TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.1)),
    ]);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('sameProductChance', 1.0)
        ->assertSeeHtml('data-test="signal-barcode"')
        // No AI ran, so the preview does not credit one.
        ->assertDontSeeHtml('data-test="signal-ai"');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT);
});

test('a failed check logs a warning and leaves the preview unwarned', function (): void {
    Http::fake(fakeJsonLdOffer() + [TypeSafeClient::ENDPOINT => Http::response([], 500)]);
    Log::spy();
    $product = productWithShopChecks();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('sameProductChance', null)
        ->assertDontSeeHtml('data-test="same-product-warning"');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Same-product check failed'))->once();
});

test('a spent daily budget sends nothing', function (): void {
    config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
    Http::fake(fakeJsonLdOffer() + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.1))]);
    $product = productWithShopChecks();
    RateLimiter::hit('shop-check:add-shop:user:' . $product->user_id, 86400);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('sameProductChance', null);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT);
});

test('the add_shop preview over MCP carries the doubt as a note', function (): void {
    Http::fake(fakeJsonLdOffer(url: 'https://shop.example.com/p/2', name: 'Whiskas Adult Zalm 4 x 85 g') + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.2))]);
    $product = productWithShopChecks();

    DipCatchServer::actingAs($product->user()->sole())
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/2'])
        ->assertOk()
        ->assertSee('An AI check doubts that this page sells the same product');
});

test('the add-shop preview offers the check to a Pro account that has it off', function (): void {
    Http::fake(fakeJsonLdOffer());
    $product = productWithShopChecks(optedIn: false);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSeeLivewire(AiFeaturePrompt::class);
});

test('the add-shop preview shows the AI check\'s match when it finds the same product', function (): void {
    Http::fake(fakeJsonLdOffer(name: 'Whiskas Adult Zalm 4 x 85 g') + [TypeSafeClient::ENDPOINT => Http::response(sameProductAnswer(0.93))]);
    $product = productWithShopChecks();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSee('AI check: 93% match')
        ->assertDontSeeHtml('data-test="same-product-warning"');
});
