<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/beans' => Http::response(jsonLdPage('4.99'), 200, ['Content-Type' => 'text/html']),
        'https://shop.test/p/broken' => Http::response('<html><body>no price</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
});

function sharedReadingShop(User $user, string $url = 'https://shop.test/p/beans', ?string $priceSelector = null, ?string $variantKey = null): Shop
{
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

    return Shop::factory()->for($product)->create([
        'url' => $url,
        'currency' => 'EUR',
        'price_selector' => $priceSelector,
        'variant_key' => $variantKey,
    ]);
}

function sharedReadingCheck(Shop $shop, bool $manual = false): Shop
{
    new CheckShopPrice($shop, manual: $manual)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    return $shop->refresh();
}

function sharedReadingFetches(string $url = 'https://shop.test/p/beans'): int
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $url)->count();
}

it('reads a page once for two people tracking it, and dates the second at the read', function (): void {
    $first = sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    $this->travel(2)->hours();
    $second = sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    expect(sharedReadingFetches())->toBe(1)
        ->and($second->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $second->current_price)->toBe('4.99')
        // Dated at the reading, so it comes due again when the reading is old.
        ->and($second->last_checked_at?->toIso8601String())->toBe($first->last_checked_at?->toIso8601String());
});

it('fetches again once the reading is older than the row\'s own interval', function (): void {
    sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    $this->travel(25)->hours();
    sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    expect(sharedReadingFetches())->toBe(2);
});

it('gives a Pro row nothing older than its own six hours', function (): void {
    sharedReadingCheck(sharedReadingShop(User::factory()->create()));
    $pro = User::factory()->create();
    subscribeUser($pro);

    $this->travel(7)->hours();
    sharedReadingCheck(sharedReadingShop($pro));

    expect(sharedReadingFetches())->toBe(2);
});

it('always fetches for a person asking for a check', function (): void {
    sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    sharedReadingCheck(sharedReadingShop(User::factory()->create()), manual: true);

    expect(sharedReadingFetches())->toBe(2);
});

it('never shares with a row that reads the page its own way', function (?string $priceSelector, ?string $variantKey): void {
    sharedReadingCheck(sharedReadingShop(User::factory()->create()));

    sharedReadingCheck(sharedReadingShop(User::factory()->create(), priceSelector: $priceSelector, variantKey: $variantKey));

    expect(sharedReadingFetches())->toBe(2);
})->with([
    'its own price selector' => ['.price', null],
    'another variant' => [null, 'SKU-2'],
]);

it('shares no failure: the next row fetches for itself', function (): void {
    $first = sharedReadingCheck(sharedReadingShop(User::factory()->create(), 'https://shop.test/p/broken'));
    sharedReadingCheck(sharedReadingShop(User::factory()->create(), 'https://shop.test/p/broken'));

    expect($first->last_status)->not->toBe(ScrapeStatus::Ok)
        ->and(sharedReadingFetches('https://shop.test/p/broken'))->toBe(2);
});
