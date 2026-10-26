<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\Hosts\JumboAdapter;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Offers\JumboUpcomingPromotion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const JUMBO_URL = 'https://www.jumbo.com/producten/pitloze-witte-druiven-500-g-306009BAK';

beforeEach(function (): void {
    Cache::flush();
    config(['dipcatch.upcoming_offers.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Amsterdam'));
});

/**
 * Jumbo's GraphQL answer for one SKU (observed 2026-10-24 for 306009BAK on
 * 28 October): prices in cents, promotion days with their offset.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $promotions  start, end, tag
 * @return array<string, mixed>
 */
function jumboGraphQl(array $promotions = [['2026-10-28T00:01:00+01:00', '2026-11-03T23:59:00+01:00', 'voor 1,99']], int $price = 259, ?int $promoPrice = 199): array
{
    return ['data' => ['product' => [
        'price' => ['price' => $price, 'promoPrice' => $promoPrice],
        'promotions' => array_map(fn (array $p): array => ['start' => ['iso' => $p[0]], 'end' => ['iso' => $p[1]], 'tags' => [['text' => $p[2]]]], $promotions),
    ]]];
}

function jumboExtract(): ShopSnapshot
{
    // The no-offer fixture: a product panel at 1.99 with no promotion block.
    $snapshot = app(JumboAdapter::class)->extract(JUMBO_URL, File::get(base_path('tests/Fixtures/bundle-prices/jumbo-no-offer.html')))->snapshot;
    assert($snapshot !== null);

    return $snapshot;
}

test('announces next week\'s promotion and keeps tracking today\'s price', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl(price: 259, promoPrice: 149))]);

    $snapshot = jumboExtract();

    expect($snapshot->price)->toBe('1.99')
        ->and($snapshot->promotionWindow?->hasNotStarted())->toBeTrue()
        ->and($snapshot->promotionWindow?->label)->toBe('1.49')
        ->and($snapshot->promotionWindow?->startsAt?->utc()->toIso8601String())->toBe('2026-10-27T23:01:00+00:00');
});

test('asks for Wednesday noon of the next offer week, in the formats each field needs', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl(promoPrice: 149))]);

    jumboExtract();

    Http::assertSent(function (Request $request): bool {
        $variables = json_decode((string) ($request->data()['variables'] ?? ''), true);

        return $request->hasHeader('apollographql-client-name', 'JUMBO_WEB')
            && $request->hasHeader('apollo-require-preflight', 'true')
            && $variables === ['sku' => '306009BAK', 'day' => '2026-10-28', 'moment' => '2026-10-28T12:00:00+01:00'];
    });
});

test('the reference is the Wednesday after today, from every weekday', function (string $today, string $wednesday): void {
    expect(JumboUpcomingPromotion::referenceMoment(CarbonImmutable::parse($today . ' 09:00', 'Europe/Amsterdam'))->format('Y-m-d H:i'))->toBe($wednesday . ' 12:00');
})->with([
    'Monday' => ['2026-10-26', '2026-10-28'],
    'Tuesday' => ['2026-10-27', '2026-10-28'],
    'Wednesday' => ['2026-10-28', '2026-11-04'],
    'Thursday' => ['2026-10-29', '2026-11-04'],
    'Sunday' => ['2026-11-01', '2026-11-04'],
]);

test('a multi-buy is read against next week\'s regular price', function (): void {
    // 1+1 next week on a product that costs 2.59 then, against 1.99 today.
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl([['2026-10-28T00:01:00+01:00', '2026-11-03T23:59:00+01:00', '1+1 gratis']], promoPrice: null))]);

    $snapshot = jumboExtract();

    expect($snapshot->promotionWindow?->label)->toBe('1+1 gratis')
        ->and($snapshot->bundleOffer?->quantity)->toBe(2)
        ->and($snapshot->bundleOffer?->totalPrice)->toBe('2.59')
        ->and($snapshot->trackedPrice())->toBe('1.99');
});

test('a promotion that already runs is no announcement', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl([['2026-10-21T00:01:00+02:00', '2026-11-03T23:59:59+01:00', 'voor 1,49']], promoPrice: 149))]);

    expect(jumboExtract()->promotionWindow)->toBeNull();
});

test('a running promotion on the page asks nothing', function (): void {
    CarbonImmutable::setTestNow('2026-09-12 12:00:00 Europe/Amsterdam');
    Http::fake();

    app(JumboAdapter::class)->extract('https://www.jumbo.com/producten/x-752107PAK', File::get(base_path('tests/Fixtures/bundle-prices/jumbo-free-item.html')));

    Http::assertNothingSent();
});

test('a URL naming no SKU asks nothing', function (): void {
    Http::fake();

    app(JumboAdapter::class)->extract('https://www.jumbo.com/producten/lays-control', File::get(base_path('tests/Fixtures/bundle-prices/jumbo-no-offer.html')));

    Http::assertNothingSent();
});

test('an error from Jumbo claims nothing, keeps the check green and backs off', function (array|string $body, int $status): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response($body, $status)]);

    $snapshot = jumboExtract();
    jumboExtract();

    expect($snapshot->price)->toBe('1.99')
        ->and($snapshot->promotionWindowAuthoritative)->toBeFalse()
        ->and($snapshot->bundleOfferAuthoritative)->toBeTrue();
    Http::assertSentCount(1);
})->with([
    'GraphQL errors' => [['errors' => [['message' => 'DateTimeParseException']]], 200],
    'CSRF refusal' => [['errors' => [['message' => 'CSRF_ERROR']]], 400],
    'server error' => ['', 503],
    'an answer without data' => [['data' => null], 200],
]);

test('a connection Jumbo resets claims nothing and keeps the check green', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => fn () => Http::failedConnection()]);

    $snapshot = jumboExtract();

    expect($snapshot->price)->toBe('1.99')
        ->and($snapshot->promotionWindowAuthoritative)->toBeFalse();
});

test('one answer per SKU and week serves later checks', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl(promoPrice: 149))]);

    jumboExtract();
    jumboExtract();

    Http::assertSentCount(1);
});

test('a check waiting on another\'s lookup sends no request of its own', function (): void {
    Http::fake();
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('jumbo:upcoming:v1:306009BAK:2026-10-28:lock', 30);
    $lock->get();

    $snapshot = jumboExtract();
    $lock->release();

    expect($snapshot->promotionWindowAuthoritative)->toBeFalse();
    Http::assertNothingSent();
});

test('a failed lookup keeps the stored announcement but stores the page\'s new price', function (): void {
    $url = 'https://www.jumbo.com/producten/x-306009BAK';
    Http::fake([
        'https://www.jumbo.com/robots.txt' => Http::response('', 404),
        $url => Http::response(File::get(base_path('tests/Fixtures/bundle-prices/jumbo-no-offer.html'))),
        JumboUpcomingPromotion::URL . '*' => Http::response('', 503),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => $url,
        'currency' => 'EUR',
        'current_price' => '3.00',
        'single_item_price' => '3.00',
        'bundle_quantity' => 2,
        'bundle_total_price' => '3.00',
        'promotion_starts_at' => '2026-10-27 23:01:00',
        'promotion_ends_at' => '2026-11-03 22:59:00',
        'promotion_label' => '1+1 gratis',
    ]);

    dispatch_sync(new CheckShopPrice($shop));
    $shop->refresh();

    expect((string) $shop->current_price)->toBe('1.99')
        ->and($shop->promotionWindow()?->hasNotStarted())->toBeTrue()
        ->and($shop->bundleOffer())->toBeNull();
});

test('a failed lookup does not write an ended page window over a stored announcement', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response('', 503)]);
    $ended = PromotionWindow::make(endsAt: CarbonImmutable::now()->subDay(), startsAt: CarbonImmutable::now()->subDays(7));
    $page = new ShopSnapshot(title: 'Druiven', imageUrl: null, price: '1.99', currency: 'EUR', inStock: true, raw: [], promotionWindow: $ended, promotionWindowAuthoritative: true);

    $snapshot = app(JumboUpcomingPromotion::class)->applyTo($page, JUMBO_URL);

    expect($snapshot->promotionWindow)->toBeNull()
        ->and($snapshot->promotionWindowAuthoritative)->toBeFalse();
});

test('an announced price no lower than today is no upcoming deal, and says so', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl(price: 259, promoPrice: 199))]);

    $snapshot = jumboExtract();

    expect($snapshot->promotionWindow)->toBeNull()
        ->and($snapshot->promotionWindowAuthoritative)->toBeTrue();
});

test('of a running promotion and a later one, the later one is announced', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl([
        ['2026-10-21T00:01:00+02:00', '2026-11-03T23:59:59+01:00', 'voor 1,49'],
        ['2026-11-04T00:01:00+01:00', '2026-11-10T23:59:00+01:00', '1+1 gratis'],
    ], promoPrice: 149))]);

    $window = jumboExtract()->promotionWindow;

    expect($window?->label)->toBe('1+1 gratis')
        ->and($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('Y-m-d'))->toBe('2026-11-04');
});

test('without next week\'s regular price a multi-buy is announced but stored without terms', function (): void {
    Http::fake([JumboUpcomingPromotion::URL . '*' => Http::response(['data' => ['product' => [
        'price' => ['price' => null, 'promoPrice' => null],
        'promotions' => [['start' => ['iso' => '2026-10-28T00:01:00+01:00'], 'end' => ['iso' => '2026-11-03T23:59:00+01:00'], 'tags' => [['text' => '1+1 gratis']]]],
    ]]])]);

    $snapshot = jumboExtract();

    expect($snapshot->promotionWindow?->label)->toBe('1+1 gratis')
        ->and($snapshot->bundleOffer)->toBeNull();
});

test('a clean answer without a promotion ends a stored announcement through a check', function (): void {
    $url = 'https://www.jumbo.com/producten/x-306009BAK';
    Http::fake([
        'https://www.jumbo.com/robots.txt' => Http::response('', 404),
        $url => Http::response(File::get(base_path('tests/Fixtures/bundle-prices/jumbo-no-offer.html'))),
        JumboUpcomingPromotion::URL . '*' => Http::response(jumboGraphQl([])),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => $url,
        'currency' => 'EUR',
        'current_price' => '1.99',
        'promotion_starts_at' => '2026-10-27 23:01:00',
        'promotion_ends_at' => '2026-11-03 22:59:00',
        'promotion_label' => '1.49',
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect($shop->refresh()->promotionWindow())->toBeNull();
});
