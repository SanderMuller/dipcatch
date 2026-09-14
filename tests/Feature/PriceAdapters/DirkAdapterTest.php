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

    expect($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe($validUntil);
});

test('a packaging record for another product is not this product\'s pack size', function (): void {
    // The payload prices article 999999 while the URL names 115212.
    $html = dirkPage(productId: '999999');

    $result = $this->adapter->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});
