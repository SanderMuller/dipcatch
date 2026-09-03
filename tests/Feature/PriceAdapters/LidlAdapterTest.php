<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\LidlAdapter;

beforeEach(function (): void {
    $this->adapter = new LidlAdapter();
});

test('skips when the URL host is not lidl.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/lay-s/p1', '<html></html>')->isSkip())->toBeTrue();
});

test('extracts the JSON-LD price and augments the payload packaging as authoritative pack size', function (): void {
    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s/p10033095', lidlPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->packSize)->toBe('370 g')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue();
});

test('still succeeds without a pack size when the payload is missing', function (): void {
    $html = (string) preg_replace('/<script type="application\/json" id="__NUXT_DATA__">.*?<\/script>/s', '', lidlPage());

    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s/p10033095', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('fails with a lidl-specific reason when the page has no JSON-LD', function (): void {
    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s/p10033095', '<html><body>x</body></html>');

    expect($result->isSuccess())->toBeFalse();
});

test('reads the offer period from the in-store badge', function (): void {
    $result = new LidlAdapter()->extract('https://www.lidl.nl/p/lay-s/p10033095', lidlPage());

    expect($result->snapshot?->promotionWindow?->isRunning())->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a page stating no period reports none, and says so', function (): void {
    $result = new LidlAdapter()->extract(
        'https://www.lidl.nl/p/lay-s/p10033095',
        lidlPage(validFrom: null, validUntil: null),
    );

    expect($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->promotionWindow)->toBeNull()
        // Authoritative, so an offer that ended clears the stored period.
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('badges that disagree on the period yield none', function (): void {
    $result = new LidlAdapter()->extract(
        'https://www.lidl.nl/p/lay-s/p10033095',
        lidlPage(secondWindowUntil: '+10 days'),
    );

    expect($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->promotionWindow)->toBeNull();
});
