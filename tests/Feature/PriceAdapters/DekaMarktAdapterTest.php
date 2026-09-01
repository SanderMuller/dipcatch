<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\DekaMarktAdapter;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->adapter = new DekaMarktAdapter();
});

function dekaMarktUrl(string $productId = '126549'): string
{
    return "https://www.dekamarkt.nl/producten/x/x/x/{$productId}";
}

test('skips a host that is not dekamarkt.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/1', dekaMarktPage())->isSkip())->toBeTrue();
});

test('reports the offer price while the offer runs — the number the shopper sees', function (): void {
    $result = $this->adapter->extract(dekaMarktUrl(), dekaMarktPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.59')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Knorr Kruidenmix airfryer knoflook kip')
        ->and($result->snapshot?->packSize)->toBe('70 g')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue()
        ->and($result->snapshot?->imageUrl)
        ->toBe('https://web-fileserver.dekamarkt.nl/artikelen/492657_2026-08-25_14-04-01-473_57ec706c.png');
});

test('falls back to the shelf price once the offer window has closed', function (): void {
    $page = dekaMarktPage(offerStart: '-14 days', offerEnd: '-7 days');

    expect($this->adapter->extract(dekaMarktUrl(), $page)->snapshot?->price)->toBe('1.95');
});

test('uses the shelf price when there is no offer at all', function (): void {
    $page = dekaMarktPage(offerPrice: null, offerStart: null, offerEnd: null);

    expect($this->adapter->extract(dekaMarktUrl(), $page)->snapshot?->price)->toBe('1.95');
});

test('an undated or unreadable offer window falls back to the shelf price', function (?string $start, ?string $end): void {
    // The payload keeps last week's offer around, so an unusable window is
    // not evidence of a live discount — trusting it would resurrect an
    // expired price as a drop.
    $page = dekaMarktPage(offerStart: $start, offerEnd: $end);

    expect($this->adapter->extract(dekaMarktUrl(), $page)->snapshot?->price)->toBe('1.95');
})->with([
    'no dates at all' => [null, null],
    'missing start' => [null, '+6 days'],
    'missing end' => ['-1 day', null],
]);

test('the offer window is judged as an instant, not as wall-clock text', function (string $now, string $expected): void {
    // Fixed window in Amsterdam time; "now" is given in UTC, so a naive
    // string or wrong-zone comparison lands on the other side of the edge.
    $this->travelTo(CarbonImmutable::parse($now));

    $page = dekaMarktPageWithWindow('2026-09-01T00:00:00.000+02:00', '2026-09-07T23:59:59.000+02:00');

    expect($this->adapter->extract(dekaMarktUrl(), $page)->snapshot?->price)->toBe($expected);
})->with([
    'one minute before the window opens' => ['2026-08-31T21:59:00Z', '1.95'],
    'just inside the window' => ['2026-08-31T22:01:00Z', '1.59'],
    'the last second of the window' => ['2026-09-07T21:59:59Z', '1.59'],
    'one second after it closes' => ['2026-09-07T22:00:00Z', '1.95'],
]);

test('an id the payload does not carry fails rather than guessing', function (): void {
    $result = $this->adapter->extract(dekaMarktUrl('999999'), dekaMarktPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('dekamarkt_no_product');
});

test('a page without the payload fails with a DekaMarkt-specific reason', function (): void {
    $result = $this->adapter->extract(dekaMarktUrl(), '<html><body>nothing</body></html>');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('dekamarkt_no_payload');
});

test('a URL without a product id is refused rather than priced from a related product', function (): void {
    $result = $this->adapter->extract('https://www.dekamarkt.nl/producten/x/x/x/', dekaMarktPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('dekamarkt_no_product_id');
});
