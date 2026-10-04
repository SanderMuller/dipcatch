<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\PoieszAdapter;
use App\Services\Poiesz\PoieszOffers;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    // Inside the week poieszOffers() states, so its offers are running.
    $this->travelTo('2026-10-06 12:00:00');
    $this->adapter = app(PoieszAdapter::class);
});

function poieszUrl(string $productId = '278550'): string
{
    return "https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/{$productId}";
}

test('skips a host that is not Poiesz', function (): void {
    expect($this->adapter->extract('https://other.com/p/1', poieszPage())->isSkip())->toBeTrue();
});

test('extracts price, title, image, pack size and EAN from the Nuxt payload', function (): void {
    $result = $this->adapter->extract(poieszUrl(), poieszPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe("Ella's Kitchen Aardbeien met Appel 4+ Mnd.")
        ->and($result->snapshot?->imageUrl)->toBe('https://images.poiesz-supermarkten.nl/artikelen/278550.jpg')
        ->and($result->snapshot?->packSize)->toBe('120.00 Gram')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue()
        ->and($result->snapshot?->gtin)->toBe('5060503500747')
        ->and($result->snapshot?->inStock)->toBeTrue();
});

test('reads the struck-through price as the price before the offer', function (): void {
    $result = $this->adapter->extract(poieszUrl(), poieszPage(price: '2.49', strikeThroughPrice: '2.99'));

    expect($result->snapshot?->price)->toBe('2.49')
        ->and($result->snapshot?->claimedRegularPrice)->toBe('2.99')
        ->and($result->snapshot?->claimAuthoritative)->toBeTrue();
});

test('a product without a struck-through price claims no discount', function (): void {
    $result = $this->adapter->extract(poieszUrl(), poieszPage());

    expect($result->snapshot?->claimedRegularPrice)->toBeNull()
        ->and($result->snapshot?->claimAuthoritative)->toBeTrue();
});

test('a struck-through price not above the price claims no discount', function (): void {
    $result = $this->adapter->extract(poieszUrl(), poieszPage(price: '2.99', strikeThroughPrice: '2.99'));

    expect($result->snapshot?->claimedRegularPrice)->toBeNull();
});

test('a product on offer takes its period from the offers feed', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [560066, 278550]]]))]);

    $window = $this->adapter->extract(poieszUrl(), poieszPage(strikeThroughPrice: '2.99', promotionLabel: 'aanbieding'))->snapshot?->promotionWindow;

    expect($window?->startsAt->setTimezone('Europe/Amsterdam')->toDateTimeString())->toBe('2026-10-04 00:00:00')
        ->and($window?->endsAt->setTimezone('Europe/Amsterdam')->toDateTimeString())->toBe('2026-10-10 23:59:59')
        ->and($window?->label)->toBe('aanbieding');
});

test('a 1+1 label is read as a bundle', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550]]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(price: '4.99', promotionLabel: '1+1 gratis'))->snapshot;

    expect($snapshot?->bundleOffer?->quantity)->toBe(2)
        ->and($snapshot?->bundleOffer?->totalPrice)->toBe('4.99')
        ->and($snapshot?->trackedPrice())->toBe('2.50');
});

test('a product not on offer asks the feed nothing and clears a stored period and bundle', function (): void {
    Http::fake();

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage())->snapshot;

    Http::assertNothingSent();
    expect($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeTrue()
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue();
});

test('a feed that cannot be read still prices the product and leaves the stored period alone', function (Closure $feed): void {
    Http::fake([PoieszOffers::URL => $feed]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(price: '2.49', strikeThroughPrice: '2.99', promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->price)->toBe('2.49')
        ->and($snapshot?->claimedRegularPrice)->toBe('2.99')
        ->and($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();
})->with([
    'server error' => fn (): Closure => fn () => Http::response('', 500),
    'connection failure' => fn (): Closure => fn () => Http::failedConnection(),
    'body without offers' => fn (): Closure => fn () => Http::response('<html>maintenance</html>', 200),
    'request exception' => fn (): Closure => fn () => throw new RequestException(new Response(new Psr7Response(503))),
]);

test('a feed that is down is asked again after five minutes, not on the next check', function (): void {
    Http::fake([PoieszOffers::URL => Http::response('', 500)]);

    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));
    $this->travel(4)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(2);
});

test('a feed read is kept at most six hours', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550]]]))]);

    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));
    $this->travel(359)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(2);
});

test('a feed read is not kept past the end of its offers', function (): void {
    $this->travelTo('2026-10-10 23:00:00');
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550]]]))]);

    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));
    $this->travel(61)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(2);
});

test('a label without an offer is ignored and asks the feed nothing', function (): void {
    Http::fake();

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(price: '4.99', promotionLabel: '1+1 gratis', promotion: false))->snapshot;

    Http::assertNothingSent();
    expect($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue()
        ->and($snapshot?->trackedPrice())->toBe('4.99');
});

test('a payload without the offer fields leaves a stored offer alone', function (): void {
    Http::fake();

    // The recommended record carries no promotion or strike-through field.
    $snapshot = $this->adapter->extract(poieszUrl('503642'), poieszPage())->snapshot;

    Http::assertNothingSent();
    expect($snapshot?->promotionWindowAuthoritative)->toBeFalse()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeFalse()
        ->and($snapshot?->claimAuthoritative)->toBeFalse();
});

test('an offer without a label leaves a stored bundle alone', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550]]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotion: true))->snapshot;

    expect($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeFalse()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a feed whose offers carry no readable period is logged', function (): void {
    Log::spy();
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550], 'validUntil' => null]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindowAuthoritative)->toBeFalse();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'no readable periods'))->once();
});

test('an offer with an unreadable start leaves the stored period alone', function (?string $validFrom): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550], 'validFrom' => $validFrom]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();
})->with(['not a date' => 'not-a-date', 'empty' => '']);

test('a product in two offers with the same period keeps it', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([
        ['productIDs' => [278550]],
        ['productIDs' => [278550]],
    ]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindow?->endsAt->setTimezone('Europe/Amsterdam')->toDateTimeString())->toBe('2026-10-10 23:59:59')
        ->and($snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('one feed read serves the next check', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550, 560069]]]))]);

    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));
    $window = $this->adapter->extract(poieszUrl('560069'), poieszPage(productId: '560069', promotionLabel: 'aanbieding'))->snapshot?->promotionWindow;

    Http::assertSentCount(1);
    expect($window?->endsAt->setTimezone('Europe/Amsterdam')->toDateTimeString())->toBe('2026-10-10 23:59:59');
});

test('a product the feed does not list leaves the stored period alone', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [560066]]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();
});

test('a feed still on last week leaves the stored period alone and is read again soon', function (): void {
    $this->travelTo('2026-10-11 00:30:00');
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([['productIDs' => [278550]]]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();

    $this->travel(6)->minutes();
    $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'));

    Http::assertSentCount(2);
});

test('offers that disagree on the period of one product give it none', function (): void {
    Http::fake([PoieszOffers::URL => Http::response(poieszOffers([
        ['productIDs' => [278550]],
        ['productIDs' => [278550], 'validUntil' => '2026-10-18T00:00:00'],
    ]))]);

    $snapshot = $this->adapter->extract(poieszUrl(), poieszPage(promotionLabel: 'aanbieding'))->snapshot;

    expect($snapshot?->promotionWindow)->toBeNull()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();
});

test('the id in the URL decides which record wins', function (): void {
    $result = $this->adapter->extract(poieszUrl('503642'), poieszPage());

    expect($result->snapshot?->title)->toBe('Zwitsal Shampoo')
        ->and($result->snapshot?->price)->toBe('4.29');
});

test('an id the payload does not carry fails rather than guessing', function (): void {
    $result = $this->adapter->extract(poieszUrl('999999'), poieszPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('poiesz_no_product');
});

test('a page without the payload fails with a Poiesz-specific reason', function (): void {
    $result = $this->adapter->extract(poieszUrl(), '<html><body>nothing</body></html>');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('poiesz_no_payload');
});

test('a malformed EAN is dropped instead of stored', function (): void {
    $result = $this->adapter->extract(poieszUrl(), poieszPage(ean: '123'));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->gtin)->toBeNull();
});

test('a URL without a product id is refused rather than priced from a recommendation', function (): void {
    $result = $this->adapter->extract('https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/', poieszPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('poiesz_no_product_id');
});
