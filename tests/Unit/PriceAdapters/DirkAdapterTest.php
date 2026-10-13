<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\DirkAdapter;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->adapter = new DirkAdapter();
});

test('skips when the URL host is not dirk.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/1', '<html></html>')->isSkip())->toBeTrue();
});

test('extracts the JSON-LD price and augments the payload packaging as authoritative pack size', function (): void {
    $result = $this->adapter->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', dirkPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->packSize)->toBe('150 g')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue();
});

test('still succeeds without a pack size when the payload is missing', function (): void {
    $html = (string) preg_replace('/<script type="application\/json" id="__NUXT_DATA__">.*?<\/script>/s', '', dirkPage());

    $result = $this->adapter->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('a page served without the payload keeps the period the JSON-LD stated', function (): void {
    $validUntil = CarbonImmutable::now()->addDays(7)->toDateString();
    $html = str_replace(
        '"priceCurrency":"EUR"',
        '"priceCurrency":"EUR","priceValidUntil":"' . $validUntil . '"',
        dirkPage(),
    );
    $html = (string) preg_replace('/<script type="application\/json" id="__NUXT_DATA__">.*?<\/script>/s', '', $html);

    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe($validUntil);
});

test('a payload that prices another offer clears the period the JSON-LD stated', function (): void {
    $validUntil = CarbonImmutable::now()->addDays(7)->toDateString();
    $html = str_replace(
        '"priceCurrency":"EUR"',
        '"priceCurrency":"EUR","priceValidUntil":"' . $validUntil . '"',
        dirkPage(offerPrice: '2.49'),
    );

    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->snapshot?->promotionWindow)->toBeNull()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('fails with a dirk-specific reason when the page has no JSON-LD', function (): void {
    $result = $this->adapter->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', '<html><body>x</body></html>');

    expect($result->isSuccess())->toBeFalse();
});

test('reads the offer period behind the price', function (): void {
    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', dirkPage());

    expect($result->snapshot?->promotionWindow?->startsAt?->toDateString())->toBe('2026-08-26')
        ->and($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe('2026-09-08')
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a price record for another offer does not lend its period to this price', function (): void {
    // The payload prices 2.49 while the page sells at 1.69.
    $html = dirkPage(price: '1.69', offerPrice: '2.49');

    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->promotionWindow)->toBeNull();
});

test('a page with no offer record reports no period', function (): void {
    $result = new DirkAdapter()->extract(
        'https://www.dirk.nl/boodschappen/x/x/x/115212',
        dirkPage(offerPrice: null),
    );

    expect($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->promotionWindow)->toBeNull()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a URL naming no product id adds no promotion claim of its own', function (): void {
    $validUntil = CarbonImmutable::now()->addDays(7)->toDateString();
    $html = str_replace(
        '"priceCurrency":"EUR"',
        '"priceCurrency":"EUR","priceValidUntil":"' . $validUntil . '"',
        dirkPage(),
    );

    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/kaas', $html);

    expect($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe($validUntil)
        // With no id, no record in the payload is known to be this product's,
        // so neither the pack size nor the period comes from it.
        ->and($result->snapshot?->packSize)->toBeNull();
});

test('a packaging record for another product is not this product\'s pack size', function (): void {
    // The payload prices article 999999 while the URL names 115212.
    $html = dirkPage(productId: '999999');

    $result = $this->adapter->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('reads the price struck through beside the offer as the "was" price', function (?string $normal, ?string $claim): void {
    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', dirkPage(price: '2.57', offerPrice: '2.57', normalPrice: $normal));

    expect($result->snapshot?->claimedRegularPrice)->toBe($claim)
        ->and($result->snapshot?->claimAuthoritative)->toBeTrue();
})->with([
    'normally €5.15, now €2.57' => ['5.15', '5.15'],
    'no lower offer' => ['2.57', null],
    'no normal price' => [null, null],
]);

test('a price record for another offer lends no "was" price to this price', function (): void {
    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', dirkPage(price: '1.69', offerPrice: '2.49', normalPrice: '3.99'));

    expect($result->snapshot?->claimedRegularPrice)->toBeNull();
});

test('prefers the price record that states a period, for the period and the "was" price', function (): void {
    $jsonLd = json_encode(['@context' => 'http://schema.org/', '@type' => 'Product', 'name' => 'Beemster Kaas', 'offers' => ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'Price' => 1.69]], JSON_THROW_ON_ERROR);
    $payload = json_encode([
        ['productId' => 1, 'headerText' => 2, 'packaging' => 3],
        '115212',
        'Beemster Kaas',
        '150 g',
        // A record at the same price without dates, before the one with them.
        ['productId' => 1, 'offerPrice' => 5],
        1.69,
        ['productId' => 1, 'offerPrice' => 5, 'startDate' => 7, 'endDate' => 8, 'normalPrice' => 9],
        '2026-08-26',
        '2026-09-08',
        2.49,
    ], JSON_THROW_ON_ERROR);
    $html = '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body><script type="application/json" id="__NUXT_DATA__">' . $payload . '</script></body></html>';

    $result = new DirkAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe('2026-09-08')
        ->and($result->snapshot?->claimedRegularPrice)->toBe('2.49');
});
