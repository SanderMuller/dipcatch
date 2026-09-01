<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\PoieszAdapter;

beforeEach(function (): void {
    $this->adapter = new PoieszAdapter();
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
