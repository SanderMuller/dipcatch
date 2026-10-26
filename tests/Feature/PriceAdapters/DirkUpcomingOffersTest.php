<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\Hosts\DirkAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Offers\DirkUpcomingOffers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const DIRK_URL = 'https://www.dirk.nl/boodschappen/aardappelen-groente-fruit/fruit/mango/55252';

beforeEach(function (): void {
    Cache::flush();
    config(['dipcatch.upcoming_offers.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Amsterdam'));
});

/** @param  list<array<string, mixed>>  $nextWeek */
function fakeDirkOffers(array $nextWeek, array $current = []): void
{
    Http::fake([
        DirkUpcomingOffers::NEXT_WEEK_URL => Http::response(nuxtOffersPage($nextWeek)),
        DirkUpcomingOffers::CURRENT_URL => Http::response(nuxtOffersPage($current)),
    ]);
}

function mangoNextWeek(string $price = '0.99', string $start = '2026-10-28', string $end = '2026-11-03', ?string $text = 'ACTIE_'): array
{
    return ['id' => 140106, 'text' => $text, 'start' => $start, 'end' => $end, 'products' => [[55252, $price]]];
}

function dirkExtract(string $todayPrice = '1.79'): ShopSnapshot
{
    $snapshot = app(DirkAdapter::class)->extract(DIRK_URL, dirkPage(price: $todayPrice, productId: '55252', offerPrice: null))->snapshot;
    assert($snapshot !== null);

    return $snapshot;
}

test('announces next week\'s offer and keeps tracking today\'s price', function (): void {
    fakeDirkOffers([mangoNextWeek()]);

    $snapshot = dirkExtract();
    $window = $snapshot->promotionWindow;

    expect($snapshot->price)->toBe('1.79')
        ->and($window?->hasNotStarted())->toBeTrue()
        ->and($window?->label)->toBe('0.99')
        ->and($snapshot->promotionWindowAuthoritative)->toBeTrue();
});

test('reads the offer\'s days as Amsterdam retail days, not UTC instants', function (): void {
    fakeDirkOffers([mangoNextWeek()]);

    $window = dirkExtract()->promotionWindow;

    expect($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2026-10-28 00:00')
        ->and($window?->endsAt->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i:s'))->toBe('2026-11-03 23:59:59');
});

/** Fruitsalade, offer 140188: listed from Wednesday, runs Friday to Sunday. */
function dirkWeekendOffer(): array
{
    return ['id' => 140188, 'text' => 'VR, ZA & ZO_actie', 'listed' => '2026-10-28', 'start' => '2026-10-30', 'end' => '2026-11-01', 'products' => [[55252, '0.99']]];
}

test('Dirk\'s template ids are not read as wording', function (string $template): void {
    fakeDirkOffers([mangoNextWeek(text: $template)]);

    expect(dirkExtract()->promotionWindow?->label)->toBe('0.99');
})->with(['ACTIE_', "ACTIE_\n ", 'VR, ZA & ZO_actie', 'NIEUW_actie']);

test('a weekend offer runs from its Friday, not from the Wednesday it is listed from', function (): void {
    fakeDirkOffers([dirkWeekendOffer()]);

    $window = dirkExtract()->promotionWindow;

    expect($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('D Y-m-d'))->toBe('Fri 2026-10-30')
        ->and($window?->label)->toBe('0.99');
});

test('after Wednesday\'s turnover a weekend offer stays announced from the current offers page', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00', 'Europe/Amsterdam'));
    fakeDirkOffers([], [dirkWeekendOffer()]);

    $window = dirkExtract()->promotionWindow;

    expect($window?->hasNotStarted())->toBeTrue()
        ->and($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('D Y-m-d'))->toBe('Fri 2026-10-30');
});

test('a product\'s own offer price wins over the offer headline', function (): void {
    // Like Gember, offer 140109: headline 2.49, the product itself at 4.98.
    fakeDirkOffers([['id' => 140109, 'headline' => '2.49', 'start' => '2026-10-28', 'end' => '2026-11-03', 'products' => [[55252, '4.98']]]]);

    expect(dirkExtract(todayPrice: '5.98')->promotionWindow?->label)->toBe('4.98');
});

test('an offer no cheaper than today is no upcoming deal', function (): void {
    fakeDirkOffers([mangoNextWeek(price: '1.79')]);

    expect(dirkExtract()->promotionWindow)->toBeNull();
});

test('two offers for one product with different days give none', function (): void {
    fakeDirkOffers([mangoNextWeek(), ['id' => 2, 'start' => '2026-10-30', 'end' => '2026-11-01', 'products' => [[55252, '0.89']]]]);

    expect(dirkExtract()->promotionWindow)->toBeNull();
});

test('an offer running now on the current page does not cancel next week\'s', function (): void {
    fakeDirkOffers([mangoNextWeek()], [mangoNextWeek(start: '2026-10-21', end: '2026-10-27')]);

    expect(dirkExtract()->promotionWindow?->hasNotStarted())->toBeTrue();
});

test('an offer running now does not cancel a later one listed on the same page', function (): void {
    fakeDirkOffers([], [mangoNextWeek(start: '2026-10-21', end: '2026-10-27'), mangoNextWeek()]);

    expect(dirkExtract()->promotionWindow?->hasNotStarted())->toBeTrue();
});

test('an offer with no products is skipped', function (): void {
    fakeDirkOffers([['id' => 140112, 'start' => '2026-10-28', 'end' => '2026-11-03', 'products' => []]]);

    expect(dirkExtract()->promotionWindow)->toBeNull();
});

test('a running offer on the product page wins without reading the offers pages', function (): void {
    Http::fake();

    $snapshot = app(DirkAdapter::class)->extract(DIRK_URL, dirkPage(price: '0.99', productId: '55252', offerPrice: '0.99', offerStart: '2026-10-21', offerEnd: '2026-10-27'))->snapshot;

    expect($snapshot?->promotionWindow?->isRunning())->toBeTrue();
    Http::assertNothingSent();
});

test('a URL naming no product id reads no offers pages', function (): void {
    Http::fake();

    app(DirkAdapter::class)->extract('https://www.dirk.nl/boodschappen/x/x/kaas', dirkPage(offerPrice: null));

    Http::assertNothingSent();
});

test('offers pages that fail claim nothing, keep the check green and back off', function (Closure $failure): void {
    Http::fake([DirkUpcomingOffers::NEXT_WEEK_URL => $failure, DirkUpcomingOffers::CURRENT_URL => $failure]);

    $snapshot = dirkExtract();
    dirkExtract();

    expect($snapshot->price)->toBe('1.79')
        ->and($snapshot->promotionWindowAuthoritative)->toBeFalse();
    Http::assertSentCount(1);
})->with([
    'server error' => fn (): Closure => fn () => Http::response('', 500),
    'connection failure' => fn (): Closure => fn () => Http::failedConnection(),
    'a page without the Nuxt payload' => fn (): Closure => fn () => Http::response('<html>maintenance</html>', 200),
    'a Nuxt page that lists no offers' => fn (): Closure => fn () => Http::response('<html><body><script type="application/json" id="__NUXT_DATA__">[{"page":1},"onderhoud"]</script></body></html>', 200),
]);

test('a check that waited on a read that failed does not read again', function (): void {
    Http::fake();
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('dirk:announced-offers:v1:lock', 30);
    $lock->get();
    // While this check waits, the read it waits on fails and lets go.
    Sleep::whenFakingSleep(function () use ($lock): void {
        Cache::put('dirk:announced-offers:v1:failed', true, 300);
        $lock->release();
    });

    expect(dirkExtract()->promotionWindowAuthoritative)->toBeFalse();
    Http::assertNothingSent();
});

test('a check that waited on a read that succeeded uses its answer', function (): void {
    Http::fake();
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('dirk:announced-offers:v1:lock', 30);
    $lock->get();
    Sleep::whenFakingSleep(function () use ($lock): void {
        Cache::put('dirk:announced-offers:v1', ['55252' => ['2026-10-28T00:00:00+01:00', '2026-11-03T23:59:59+01:00', '0.99', 'ACTIE_']], 300);
        $lock->release();
    });

    $snapshot = dirkExtract();

    expect($snapshot->promotionWindow?->hasNotStarted())->toBeTrue()
        ->and($snapshot->promotionWindowAuthoritative)->toBeTrue();
    Http::assertNothingSent();
});

test('offers pages that failed are read again after five minutes', function (): void {
    Http::fake([DirkUpcomingOffers::NEXT_WEEK_URL => Http::response('', 500)]);

    dirkExtract();
    $this->travel(4)->minutes();
    dirkExtract();
    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    dirkExtract();
    Http::assertSentCount(2);
});

test('a clean read without the offer ends a stored announcement through a check', function (): void {
    Http::fake([
        'https://www.dirk.nl/robots.txt' => Http::response('', 404),
        DIRK_URL => Http::response(dirkPage(price: '1.79', productId: '55252', offerPrice: null)),
        DirkUpcomingOffers::NEXT_WEEK_URL => Http::response(nuxtOffersPage([])),
        DirkUpcomingOffers::CURRENT_URL => Http::response(nuxtOffersPage([])),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => DIRK_URL,
        'currency' => 'EUR',
        'current_price' => '1.79',
        'promotion_starts_at' => '2026-10-27 23:00:00',
        'promotion_ends_at' => '2026-11-03 22:59:59',
        'promotion_label' => '0.99',
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect($shop->refresh()->promotionWindow())->toBeNull();
});

test('one read serves later checks from the cache', function (): void {
    fakeDirkOffers([mangoNextWeek()]);

    dirkExtract();
    dirkExtract();

    Http::assertSentCount(2);
});

test('a check waiting on another\'s read sends no request of its own', function (): void {
    Http::fake();
    // The wait runs on the clock, which the test froze.
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('dirk:announced-offers:v1:lock', 30);
    $lock->get();

    $snapshot = dirkExtract();
    $lock->release();

    expect($snapshot->promotionWindowAuthoritative)->toBeFalse();
    Http::assertNothingSent();
});

test('a failed read keeps the stored upcoming window through a check', function (): void {
    Http::fake([
        'https://www.dirk.nl/robots.txt' => Http::response('', 404),
        DIRK_URL => Http::response(dirkPage(price: '1.69', productId: '55252', offerPrice: null)),
        DirkUpcomingOffers::NEXT_WEEK_URL => Http::response('', 500),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => DIRK_URL,
        'currency' => 'EUR',
        'current_price' => '1.79',
        'promotion_starts_at' => '2026-10-27 23:00:00',
        'promotion_ends_at' => '2026-11-03 22:59:59',
        'promotion_label' => '0.99',
    ]);

    dispatch_sync(new CheckShopPrice($shop));
    $shop->refresh();

    expect((string) $shop->current_price)->toBe('1.69')
        ->and($shop->promotionWindow()?->hasNotStarted())->toBeTrue();
});

test('nothing is read while the switch is off', function (): void {
    config(['dipcatch.upcoming_offers.enabled' => false]);
    Http::fake();

    $snapshot = dirkExtract();

    expect($snapshot->promotionWindow)->toBeNull()
        ->and($snapshot->promotionWindowAuthoritative)->toBeTrue();
    Http::assertNothingSent();
});
