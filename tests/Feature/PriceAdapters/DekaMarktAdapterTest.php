<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\DekaMarktAdapter;

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

test('an undated offer price is trusted rather than dropped', function (): void {
    $page = dekaMarktPage(offerStart: null, offerEnd: null);

    expect($this->adapter->extract(dekaMarktUrl(), $page)->snapshot?->price)->toBe('1.59');
});

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
