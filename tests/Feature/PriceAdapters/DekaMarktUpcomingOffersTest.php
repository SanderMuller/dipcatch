<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\DekaMarktAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Offers\DekaMarktUpcomingOffers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const DEKA_URL = 'https://www.dekamarkt.nl/producten/x/x/x/126549';

beforeEach(function (): void {
    Cache::flush();
    config(['dipcatch.upcoming_offers.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Amsterdam'));
});

/** @param  list<array<string, mixed>>  $offers */
function fakeDekaOffers(array $offers): void
{
    Http::fake([DekaMarktUpcomingOffers::URL => Http::response(nuxtOffersPage($offers))]);
}

function dekaNextWeek(string $price = '1.49', ?string $text = 'per stuk 1,49'): array
{
    // Disclaimer days as the folder states them: Tuesday to Monday.
    return ['id' => 137910, 'text' => $text, 'start' => '2026-10-27', 'end' => '2026-11-02', 'products' => [[126549, $price]]];
}

/** A shelf price record whose days cover today with offer price 0, as on captured products 99405 and 3111. */
function dekaShelfExtract(string $normalPrice = '1.95'): ShopSnapshot
{
    $snapshot = app(DekaMarktAdapter::class)->extract(DEKA_URL, dekaMarktPage(normalPrice: $normalPrice, offerPrice: '0'))->snapshot;
    assert($snapshot !== null);

    return $snapshot;
}

test('a shelf price record running today still looks for next week\'s offer', function (): void {
    fakeDekaOffers([dekaNextWeek()]);

    $snapshot = dekaShelfExtract();
    $window = $snapshot->promotionWindow;

    expect($snapshot->price)->toBe('1.95')
        ->and($snapshot->claimedRegularPrice)->toBeNull()
        ->and($window?->hasNotStarted())->toBeTrue()
        ->and($window?->label)->toBe('1.49')
        ->and($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('D Y-m-d H:i'))->toBe('Tue 2026-10-27 00:00')
        ->and($window?->endsAt->setTimezone('Europe/Amsterdam')->format('D Y-m-d H:i:s'))->toBe('Mon 2026-11-02 23:59:59');
});

test('a product\'s own days win over the folder\'s, as DekaMarkt\'s site follows them', function (): void {
    // 99405 on 26 October 2026: the folder says Tuesday 27, the product record and the site Monday 26.
    fakeDekaOffers([['id' => 137910, 'text' => 'per zak 1,49', 'start' => '2026-10-27', 'end' => '2026-11-02', 'products' => [[126549, '1.49', '2026-10-26T00:00:00.000+01:00', '2026-11-01T23:59:59.000+01:00']]]]);

    $window = dekaShelfExtract()->promotionWindow;

    expect($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('D Y-m-d H:i'))->toBe('Mon 2026-10-26 00:00')
        ->and($window?->endsAt->setTimezone('Europe/Amsterdam')->format('D Y-m-d H:i:s'))->toBe('Sun 2026-11-01 23:59:59');
});

test('a product that states only its start keeps the folder\'s end', function (): void {
    fakeDekaOffers([['id' => 137910, 'start' => '2026-10-27', 'end' => '2026-11-02', 'products' => [[126549, '1.49', '2026-10-26T00:00:00.000+01:00', null]]]]);

    $window = dekaShelfExtract()->promotionWindow;

    expect($window?->startsAt?->setTimezone('Europe/Amsterdam')->format('D Y-m-d'))->toBe('Mon 2026-10-26')
        ->and($window?->endsAt->setTimezone('Europe/Amsterdam')->format('D Y-m-d H:i:s'))->toBe('Mon 2026-11-02 23:59:59');
});

test('one day stated with two offsets is one offer, not a conflict', function (): void {
    fakeDekaOffers([
        ['id' => 1, 'start' => '2026-10-27', 'end' => '2026-11-02', 'products' => [[126549, '1.49', '2026-10-26T00:00:00.000+01:00', '2026-11-02T23:59:59.000+01:00']]],
        ['id' => 2, 'start' => '2026-10-27', 'end' => '2026-11-02', 'products' => [[126549, '1.49', '2026-10-25T23:00:00.000Z', '2026-11-02T22:59:59.000Z']]],
    ]);

    expect(dekaShelfExtract()->promotionWindow?->hasNotStarted())->toBeTrue();
});

test('a 1+1 at the same price is announced in DekaMarkt\'s words', function (): void {
    // Hak 3111: offer and normal price both 2.09.
    fakeDekaOffers([dekaNextWeek(price: '1.95', text: '1+1 GRATIS')]);

    expect(dekaShelfExtract()->promotionWindow?->label)->toBe('1+1 GRATIS');
});

test('an offer stated only as a price no lower than today is no upcoming deal, and says so', function (): void {
    fakeDekaOffers([dekaNextWeek(price: '1.95', text: 'per stuk 1,95')]);

    $snapshot = dekaShelfExtract();

    expect($snapshot->promotionWindow)->toBeNull()
        ->and($snapshot->promotionWindowAuthoritative)->toBeTrue();
});

test('two offers for one product with different days give none', function (): void {
    fakeDekaOffers([dekaNextWeek(), ['id' => 2, 'start' => '2026-10-28', 'end' => '2026-11-02', 'products' => [[126549, '1.29']]]]);

    expect(dekaShelfExtract()->promotionWindow)->toBeNull();
});

test('a running offer on the product page wins without reading the offers page', function (): void {
    Http::fake();

    $snapshot = app(DekaMarktAdapter::class)->extract(DEKA_URL, dekaMarktPage())->snapshot;

    expect($snapshot?->promotionWindow?->isRunning())->toBeTrue();
    Http::assertNothingSent();
});

test('an offers page that fails claims nothing and keeps the check green', function (Closure $failure): void {
    Http::fake([DekaMarktUpcomingOffers::URL => $failure]);

    $snapshot = dekaShelfExtract();

    expect($snapshot->price)->toBe('1.95')
        ->and($snapshot->promotionWindowAuthoritative)->toBeFalse();
})->with([
    'server error' => fn (): Closure => fn () => Http::response('', 503),
    'connection failure' => fn (): Closure => fn () => Http::failedConnection(),
    'a page without the Nuxt payload' => fn (): Closure => fn () => Http::response('<html>maintenance</html>', 200),
]);

test('a check waiting on another\'s read sends no request of its own', function (): void {
    Http::fake();
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('dekamarkt:announced-offers:v1:lock', 30);
    $lock->get();

    $snapshot = dekaShelfExtract();
    $lock->release();

    expect($snapshot->promotionWindowAuthoritative)->toBeFalse();
    Http::assertNothingSent();
});
