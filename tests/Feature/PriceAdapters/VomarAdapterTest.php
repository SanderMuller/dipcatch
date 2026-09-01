<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\VomarAdapter;

beforeEach(function (): void {
    $this->adapter = new VomarAdapter();
});

test('skips a host that is not vomar.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/1', vomarPage())->isSkip())->toBeTrue();
});

test('reads price, title, pack size, EAN and image from the Nuxt 2 state', function (): void {
    $result = $this->adapter->extract('https://www.vomar.nl/producten/vers/x/x/119614', vomarPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.39')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Aardappelgratin Kaas')
        ->and($result->snapshot?->packSize)->toBe('500 gram')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue()
        ->and($result->snapshot?->gtin)->toBe('8718989087319')
        ->and($result->snapshot?->imageUrl)
        ->toBe('https://d3vricquk1sjgf.cloudfront.net/product-images/21070f17-ce64-430b-ae24-ef8572a67a37.png');
});

test('a minified name falls back to the page heading instead of the variable', function (): void {
    $result = $this->adapter->extract('https://www.vomar.nl/producten/vers/x/x/467456', vomarPage(description: null));

    expect($result->snapshot?->title)->toBe('Ontbijtkoek');
});

test('a minified price fails rather than guessing', function (): void {
    $html = str_replace('price:2.39', 'price:aB', vomarPage());

    $result = $this->adapter->extract('https://www.vomar.nl/producten/vers/x/x/119614', $html);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('vomar_no_price');
});

test('a page without the product state fails with a Vomar-specific reason', function (): void {
    $result = $this->adapter->extract('https://www.vomar.nl/producten/vers/x/x/119614', '<html><body>x</body></html>');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('vomar_no_product');
});

test('a malformed EAN is dropped instead of stored', function (): void {
    $result = $this->adapter->extract('https://www.vomar.nl/producten/vers/x/x/119614', vomarPage(ean: '8718989087310'));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->gtin)->toBeNull();
});
