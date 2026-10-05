<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use App\PriceAdapters\Hosts\LidlAdapter;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->adapter = app(LidlAdapter::class);
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
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse()
        // Lidl's JSON-LD states no period, so its authority claim must not
        // survive to clear a promotion an earlier check read.
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeFalse();
});

test('fails with a lidl-specific reason when the page has no JSON-LD', function (): void {
    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s/p10033095', '<html><body>x</body></html>');

    expect($result->isSuccess())->toBeFalse();
});

test('reads the offer period from the in-store badge', function (): void {
    $result = app(LidlAdapter::class)->extract('https://www.lidl.nl/p/lay-s/p10033095', lidlPage());

    expect($result->snapshot?->promotionWindow?->isRunning())->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a page stating no period reports none, and says so', function (): void {
    $result = app(LidlAdapter::class)->extract(
        'https://www.lidl.nl/p/lay-s/p10033095',
        lidlPage(validFrom: null, validUntil: null),
    );

    expect($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->promotionWindow)->toBeNull()
        // Authoritative, so an offer that ended clears the stored period.
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('badges that disagree on the period yield none', function (): void {
    $result = app(LidlAdapter::class)->extract(
        'https://www.lidl.nl/p/lay-s/p10033095',
        lidlPage(secondWindowUntil: '+10 days'),
    );

    expect($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->promotionWindow)->toBeNull();
});

test('a packaging record for another product is not this product\'s pack size', function (): void {
    // The payload describes article 999999 while the URL names 10033095.
    $html = lidlPage(productId: 999999);

    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s/p10033095', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('a URL naming no product id still reads the period, but no pack size', function (): void {
    $result = $this->adapter->extract('https://www.lidl.nl/p/lay-s', lidlPage());

    expect($result->isSuccess())->toBeTrue()
        // The badge period is the payload's own, not a per-product record.
        ->and($result->snapshot?->promotionWindow?->isRunning())->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue()
        ->and($result->snapshot?->packSize)->toBeNull();
});

/**
 * A lidl.nl grocery page as observed 2026-10-05: the JSON-LD offer states no
 * price, and the product record lists the IANs the dataset is keyed by. A
 * neighbour record stands in for the related products the payload carries.
 *
 * @param  list<string>  $ians
 */
function lidlGroceryPage(array $ians = ['197872'], string $title = 'Vaatwastabletten', ?string $price = null, string $availability = 'InStoreOnly', ?string $packaging = null): string
{
    $offer = ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'availability' => $availability];

    if ($price !== null) {
        $offer['price'] = (float) $price;
    }

    $jsonLd = json_encode(['@context' => 'http://schema.org', '@type' => 'Product', 'name' => $title, 'offers' => [$offer]], JSON_THROW_ON_ERROR);

    $records = [
        ['productId' => 1, 'ians' => 2, 'keyfacts' => 3, 'price' => 5],
        10004526,
        [],
        ['title' => 4],
        $title,
        ['currencyCode' => 6],
        'EUR',
        ['productId' => 8, 'ians' => 9, 'keyfacts' => 11, 'price' => 5],
        10000006,
        [10],
        '107229',
        ['title' => 12],
        'Ribbelchips naturel',
    ];

    foreach ($ians as $ian) {
        $records[2][] = count($records);
        $records[] = $ian;
    }

    if ($packaging !== null) {
        $records[5]['packaging'] = count($records);
        $records[] = ['text' => count($records) + 1];
        $records[] = $packaging;
    }

    return '<html><head><meta property="og:image" content="https://cdn.example/tabs.png"><script type="application/ld+json">' . $jsonLd . '</script></head>'
        . '<body><script type="application/json" id="__NUXT_DATA__">' . json_encode($records, JSON_THROW_ON_ERROR) . '</script></body></html>';
}

function lidlShelfRow(string $ian, string $name, string $price, ?string $size = null, ?CarbonImmutable $refreshedAt = null): void
{
    CheckjebonPrice::query()->create([
        'supermarket' => 'lidl',
        'external_id' => $ian,
        'name' => $name,
        'price' => $price,
        'size' => $size,
        'link' => $ian,
        'refreshed_at' => $refreshedAt ?? now(),
    ]);
}

test('a grocery page without a price reads the dataset shelf price for its IAN', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.99')
        ->and($result->snapshot?->title)->toBe('Vaatwastabletten')
        ->and($result->snapshot?->imageUrl)->toBe('https://cdn.example/tabs.png')
        ->and($result->snapshot?->raw['source'] ?? null)->toBe('checkjebon')
        ->and($result->snapshot?->inStock)->toBeTrue()
        // A regular price clears a period stored from an earlier priced page.
        ->and($result->snapshot?->promotionWindow)->toBeNull()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a sold-out grocery page keeps its stock verdict with the dataset price', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage(availability: 'OutOfStock'));

    expect($result->snapshot?->price)->toBe('2.99')
        ->and($result->snapshot?->inStock)->toBeFalse();
});

test('a dataset row whose name names another product prices nothing', function (): void {
    lidlShelfRow('1482', 'Witte mini puntjes', '1.39');

    $result = $this->adapter->extract('https://www.lidl.nl/p/witbrood/p10004526', lidlGroceryPage(['1482'], 'Witbrood'));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('lidl_no_price');
});

test('a row for another pack size prices nothing', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99', size: '40 stuks');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage(packaging: '80 stuks'));

    expect($result->failureReason)->toBe('lidl_no_price');
});

test('a row for the same pack size keeps it', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99', size: '60 stuks');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage(packaging: '60 Stuks'));

    expect($result->snapshot?->price)->toBe('2.99')
        ->and($result->snapshot?->packSize)->toBe('60 stuks');
});

test('a row the dataset stopped refreshing prices nothing', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99', refreshedAt: CarbonImmutable::now()->subDays(5));

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage());

    expect($result->failureReason)->toBe('lidl_no_price');
});

test('two agreeing rows for one product price nothing', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99');
    lidlShelfRow('213266', 'Vaatwastabletten', '3.45');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage(['197872', '213266']));

    expect($result->failureReason)->toBe('lidl_no_price');
});

test('a related product\'s IAN does not price this one', function (): void {
    lidlShelfRow('107229', 'Ribbelchips naturel', '1.19');

    // The neighbour's row would agree on the name, so only the id keeps it out.
    $result = $this->adapter->extract('https://www.lidl.nl/p/ribbelchips/p10004526', lidlGroceryPage([], 'Ribbelchips naturel'));

    expect($result->failureReason)->toBe('lidl_no_price');
});

test('a price the page states wins over the dataset', function (): void {
    lidlShelfRow('197872', 'Vaatwastabs all in', '2.99');

    $result = $this->adapter->extract('https://www.lidl.nl/p/vaatwastabletten/p10004526', lidlGroceryPage(price: '3.49'));

    expect($result->snapshot?->price)->toBe('3.49');
});
