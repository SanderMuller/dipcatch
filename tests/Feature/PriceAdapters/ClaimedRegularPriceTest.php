<?php declare(strict_types=1);

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ShopDraft;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\Hosts\DekaMarktAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\PromotionWindow;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * @param  list<array<string, mixed>>  $specs
 * @param  array<string, mixed>  $extra
 */
function claimPage(string $price, array $specs = [], array $extra = []): string
{
    return withJsonLd(json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Kettle',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock', 'priceSpecification' => $specs] + $extra,
    ], JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function struck(string $price, string $type = 'https://schema.org/StrikethroughPrice', ?string $currency = 'EUR'): array
{
    return array_filter(['@type' => 'UnitPriceSpecification', 'priceType' => $type, 'price' => $price, 'priceCurrency' => $currency]);
}

/**
 * Serves `$html` for the shop page, or a server error when null. One fake
 * reads the current page, because a second `Http::fake()` would only append
 * stubs behind the first.
 */
function checkClaimShop(Shop $shop, ?string $html): void
{
    test()->page = $html;

    Http::fake(static fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/robots.txt') => Http::response('', 404),
        test()->page === null => Http::response('down', 500),
        default => Http::response(test()->page, 200, ['Content-Type' => 'text/html']),
    });

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));
}

function claimShop(): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    return Shop::factory()->for($product)->create(['url' => 'https://shop.test/p/1', 'currency' => 'EUR']);
}

test('a strikethrough price is stored as the claim on the shop and the reading, with the seller', function (): void {
    $shop = claimShop();

    checkClaimShop($shop, claimPage('80.00', [struck('100.00')], ['seller' => ['@type' => 'Organization', 'name' => 'Maxmovil NL']]));

    $check = PriceCheck::query()->where('shop_id', $shop->id)->sole();

    expect($shop->refresh()->claimed_regular_price)->toBe('100.00')
        ->and($check->claimed_regular_price)->toBe('100.00')
        ->and($check->seller)->toBe('Maxmovil NL')
        ->and($check->claim_read)->toBeTrue()
        ->and($check->shelf_inherited)->toBeFalse();
});

test('a list price, a claim in another currency, or a claim at or below the price is no claim', function (string $price, string $type, string $currency): void {
    $shop = claimShop();

    checkClaimShop($shop, claimPage('80.00', [struck($price, $type, $currency)]));

    $check = PriceCheck::query()->where('shop_id', $shop->id)->sole();

    expect($shop->refresh()->claimed_regular_price)->toBeNull()
        ->and($check->claimed_regular_price)->toBeNull()
        // The reader could have stated one, so "none" is a fact here.
        ->and($check->claim_read)->toBeTrue();
})->with([
    'list price (MSRP)' => ['100.00', 'https://schema.org/ListPrice', 'EUR'],
    'other currency' => ['100.00', 'https://schema.org/StrikethroughPrice', 'USD'],
    'equal' => ['80.00', 'https://schema.org/StrikethroughPrice', 'EUR'],
    'below' => ['70.00', 'https://schema.org/StrikethroughPrice', 'EUR'],
]);

test('a later reading without a claim clears it, and a failed reading leaves it alone', function (): void {
    $shop = claimShop();
    checkClaimShop($shop, claimPage('80.00', [struck('100.00')]));

    $this->travel(1)->days();
    checkClaimShop($shop, null);

    $failed = PriceCheck::query()->where('shop_id', $shop->id)->latest('checked_at')->first();

    expect($shop->refresh()->claimed_regular_price)->toBe('100.00')
        ->and($failed?->claim_read)->toBeNull()
        ->and($failed?->claimed_regular_price)->toBeNull();

    $this->travel(1)->days();
    checkClaimShop($shop, claimPage('100.00'));

    expect($shop->refresh()->claimed_regular_price)->toBeNull();
});

test('a reader without the concept leaves the stored claim alone and is no evidence', function (): void {
    $shop = claimShop();
    $shop->forceFill(['claimed_regular_price' => '100.00'])->save();

    // OpenGraph only: no reader here can state a claim.
    checkClaimShop($shop, '<html><head><meta property="og:title" content="Kettle"><meta property="og:price:amount" content="80.00"><meta property="og:price:currency" content="EUR"></head></html>');

    $check = PriceCheck::query()->where('shop_id', $shop->id)->sole();

    expect($shop->refresh()->claimed_regular_price)->toBe('100.00')
        ->and($check->claim_read)->toBeFalse();
});

test('repointing a shop clears its claim', function (): void {
    $shop = claimShop();
    $shop->forceFill(['claimed_regular_price' => '100.00'])->save();

    $shop->updateUrl('https://shop.test/p/2');

    expect($shop->refresh()->claimed_regular_price)->toBeNull();
});

test('AH claims its price before the bonus while the bonus runs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 12:00:00', 'Europe/Amsterdam'));
    Http::fake(ahApiProductFakes(currentPrice: '1.69', priceBeforeBonus: '2.19', bonusMechanism: '25% korting'));

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi526381')->snapshot;

    expect($snapshot?->claimedRegularPrice)->toBe('2.19')
        ->and($snapshot?->claimAuthoritative)->toBeTrue();
});

test('AH claims nothing before an announced bonus starts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 12:00:00', 'Europe/Amsterdam'));
    Http::fake(ahApiProductFakes(currentPrice: '1.69', priceBeforeBonus: '2.19', bonusStart: '2026-09-07', bonusEnd: '2026-09-13', bonusMechanism: '25% korting'));

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi526381')->snapshot;

    expect($snapshot?->price)->toBe('2.19')
        ->and($snapshot?->claimedRegularPrice)->toBeNull();
});

test('DekaMarkt claims its normal price while an offer runs, and nothing without one', function (): void {
    $withOffer = new DekaMarktAdapter()->extract('https://www.dekamarkt.nl/producten/x/x/x/126549', dekaMarktPage());
    $without = new DekaMarktAdapter()->extract('https://www.dekamarkt.nl/producten/x/x/x/126549', dekaMarktPage(offerPrice: null, offerStart: null, offerEnd: null));

    expect($withOffer->snapshot?->claimedRegularPrice)->toBe('1.95')
        ->and($withOffer->snapshot?->claimAuthoritative)->toBeTrue()
        ->and($without->snapshot?->claimedRegularPrice)->toBeNull();
});

test('a claim is dropped while a bundle applies, but kept when the bundle is only announced or over', function (string $window, ?string $expected): void {
    $promotion = match ($window) {
        'running' => PromotionWindow::make(endsAt: CarbonImmutable::now()->addDays(3), startsAt: CarbonImmutable::now()->subDay()),
        'announced' => PromotionWindow::make(endsAt: CarbonImmutable::now()->addDays(10), startsAt: CarbonImmutable::now()->addDays(3)),
        'over' => PromotionWindow::make(endsAt: CarbonImmutable::now()->subDay(), startsAt: CarbonImmutable::now()->subDays(8)),
        default => throw new InvalidArgumentException($window),
    };

    $draft = new ShopDraft(
        url: 'https://shop.test/p/1',
        adapterKey: 'jsonld',
        price: '2.49',
        currency: 'EUR',
        inStock: true,
        singleItemPrice: '2.49',
        bundleOffer: new BundleOffer(2, '4.00'),
        promotionWindow: $promotion,
        claimedRegularPrice: '2.99',
        claimRead: true,
    );

    expect($draft->keptClaim())->toBe($expected);
})->with([
    'running' => ['running', null],
    'announced' => ['announced', '2.99'],
    'over' => ['over', '2.99'],
]);

test('the add path stores the claim and the evidence on the first reading', function (): void {
    $snapshot = new JsonLdAdapter()->extract('https://shop.test/p/1', claimPage('80.00', [struck('100.00')]))->snapshot;
    assert($snapshot !== null);

    $flat = ['title' => 'Kettle', 'price' => $snapshot->price, 'currency' => 'EUR', 'in_stock' => true, 'claimed_regular_price' => $snapshot->claimedRegularPrice, 'claim_read' => true];
    $draft = ShopDraft::fromSnapshot($flat, 'https://shop.test/p/1', 'jsonld');
    $product = Product::factory()->create(['currency' => 'EUR']);

    $shop = app(AttachShop::class)($product, $draft);
    $check = $shop->priceChecks()->sole();

    expect($shop->claimed_regular_price)->toBe('100.00')
        ->and($check->claimed_regular_price)->toBe('100.00')
        ->and($check->claim_read)->toBeTrue();
});
