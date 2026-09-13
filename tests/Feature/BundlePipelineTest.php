<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('<html>bundle</html>', 200, ['Content-Type' => 'text/html']),
    ]);
});

test('authoritative bundle updates shop check product and history', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '2.85',
    ]);
    reportBundle(new ShopSnapshot(
        'Fanta',
        imageUrl: null,
        price: '2.85',
        currency: 'EUR',
        inStock: true,
        bundleOffer: new BundleOffer(2, '4.00'),
        bundleOfferAuthoritative: true,
    ));

    runBundleCheck($shop);

    $shop->refresh();
    $check = $shop->priceChecks()->first();
    $history = $product->cheapestHistory()->first();

    expect($shop->current_price)->toBe('2.00')
        ->and($shop->single_item_price)->toBe('2.85')
        ->and($shop->bundle_total_price)->toBe('4.00')
        ->and($check?->price)->toBe('2.00')
        ->and($check?->single_item_price)->toBe('2.85')
        ->and($product->fresh()?->cheapest_price)->toBe('2.00')
        ->and($history?->bundle_quantity)->toBe(2);
});

test('non-authoritative response preserves complete stored bundle pricing', function (): void {
    $shop = storedBundleShop();
    reportBundle(new ShopSnapshot('Fanta', imageUrl: null, price: '3.10', currency: 'EUR', inStock: true));

    runBundleCheck($shop);

    $shop->refresh();
    $check = $shop->priceChecks()->first();

    expect($shop->current_price)->toBe('2.00')
        ->and($shop->single_item_price)->toBe('2.85')
        ->and($shop->bundle_total_price)->toBe('4.00')
        ->and($check?->price)->toBe('2.00')
        ->and($check?->single_item_price)->toBe('2.85');
});

test('authoritative empty response clears bundle and restores shelf price', function (): void {
    $shop = storedBundleShop();
    reportBundle(new ShopSnapshot('Fanta', imageUrl: null, price: '2.85', currency: 'EUR', inStock: true, bundleOfferAuthoritative: true));

    runBundleCheck($shop);

    expect($shop->fresh()->current_price)->toBe('2.85')
        ->and($shop->fresh()->bundle_quantity)->toBeNull()
        ->and($shop->fresh()->bundle_total_price)->toBeNull();
});

test('authoritative no-saving bundle clears normalized terms', function (): void {
    $shop = storedBundleShop();
    reportBundle(new ShopSnapshot(
        'Fanta',
        imageUrl: null,
        price: '2.85',
        currency: 'EUR',
        inStock: true,
        bundleOffer: new BundleOffer(2, '6.00'),
        bundleOfferAuthoritative: true,
    ));

    runBundleCheck($shop);

    $shop->refresh();

    expect($shop->current_price)->toBe('2.85')
        ->and($shop->bundle_quantity)->toBeNull()
        ->and($shop->bundle_total_price)->toBeNull();
});

test('known bundle expiry restores shelf price after fetch failure', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('broken', 500),
    ]);
    $shop = storedBundleShop(['promotion_ends_at' => now()->subMinute()]);

    runBundleCheck($shop);

    $shop->refresh();

    expect($shop->current_price)->toBe('2.85')
        ->and($shop->bundle_quantity)->toBeNull()
        ->and($shop->last_status->value)->not->toBe('ok');
});

test('successful expired bundle response clears normalized terms', function (): void {
    $shop = storedBundleShop();
    reportBundle(new ShopSnapshot(
        'Fanta',
        imageUrl: null,
        price: '2.85',
        currency: 'EUR',
        inStock: true,
        promotionWindow: PromotionWindow::make(endsAt: now()->subMinute()),
        promotionWindowAuthoritative: true,
        bundleOffer: new BundleOffer(2, '4.00'),
        bundleOfferAuthoritative: true,
    ));

    runBundleCheck($shop);

    $shop->refresh();

    expect($shop->current_price)->toBe('2.85')
        ->and($shop->bundle_quantity)->toBeNull()
        ->and($shop->bundle_total_price)->toBeNull();
});

test('fetch failure before bundle expiry preserves tracked pricing', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('broken', 500),
    ]);
    $shop = storedBundleShop(['promotion_ends_at' => now()->addMinute()]);

    runBundleCheck($shop);

    $shop->refresh();

    expect($shop->current_price)->toBe('2.00')
        ->and($shop->single_item_price)->toBe('2.85')
        ->and($shop->bundle_quantity)->toBe(2)
        ->and($shop->bundle_total_price)->toBe('4.00');
});

test('changed bundle terms create history segment at same tracked price', function (): void {
    $shop = storedBundleShop();
    $product = $shop->product;
    $product?->recomputeCheapestShop();
    $before = $product?->cheapestHistory()->count();

    $shop->forceFill(['bundle_quantity' => 4, 'bundle_total_price' => '8.00'])->save();
    $product?->recomputeCheapestShop();

    expect($product?->cheapestHistory()->count())->toBe(($before ?? 0) + 1)
        ->and($product?->cheapestHistory()->newestFirst()->first()?->bundle_quantity)->toBe(4);
});

test('future bundle terms do not enter current price-check snapshot', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '2.85',
    ]);
    reportBundle(new ShopSnapshot(
        'Fanta',
        imageUrl: null,
        price: '2.85',
        currency: 'EUR',
        inStock: true,
        promotionWindow: PromotionWindow::make(endsAt: now()->addDays(2), startsAt: now()->addDay()),
        promotionWindowAuthoritative: true,
        bundleOffer: new BundleOffer(2, '4.00'),
        bundleOfferAuthoritative: true,
    ));

    runBundleCheck($shop);

    $shop->refresh();
    $check = $shop->priceChecks()->first();

    expect($shop->current_price)->toBe('2.85')
        ->and($shop->bundle_quantity)->toBe(2)
        ->and($shop->liveBundleOffer())->toBeNull()
        ->and($check?->price)->toBe('2.85')
        ->and($check?->bundle_quantity)->toBeNull()
        ->and($check?->bundle_total_price)->toBeNull();
});

function reportBundle(ShopSnapshot $snapshot): void
{
    $adapter = new readonly class ($snapshot) implements ShopAdapter {
        public function __construct(private ShopSnapshot $snapshot) {}

        public function key(): string
        {
            return 'test-bundle';
        }

        public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
        {
            return ExtractionResult::success($this->snapshot);
        }
    };

    app()->instance(AdapterResolver::class, new AdapterResolver([$adapter]));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function storedBundleShop(array $overrides = []): Shop
{
    return Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->state($overrides)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);
}

function runBundleCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );
}
