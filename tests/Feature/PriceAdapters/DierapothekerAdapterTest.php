<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\DierapothekerAdapter;

beforeEach(function (): void {
    $this->adapter = new DierapothekerAdapter();
    $this->url = 'https://www.dierapotheker.nl/feliway-verdamper-kat/9658/';
});

test('skips a host that is not dierapotheker.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/9658', dierapothekerPage())->isSkip())->toBeTrue();
});

test('reads the single-unit price, not the quantity-discount tier', function (): void {
    $result = $this->adapter->extract($this->url, dierapothekerPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('52.95')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Feliway Verdamper Kat')
        ->and($result->snapshot?->gtin)->toBe('3411112291649')
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://www.dierapotheker.nl/media/feliway.jpg');
});

test('takes the pack size from the variant the page says it shows', function (): void {
    $result = $this->adapter->extract($this->url, dierapothekerPage());

    expect($result->snapshot?->packSize)->toBe('Navulling 3 x 48 ml');
});

test('a page stating no variant reports no pack size', function (): void {
    $result = $this->adapter->extract($this->url, dierapothekerPage(variant: null));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->packSize)->toBeNull();
});

test('a variant named by another analytics event is not used', function (): void {
    $html = str_replace('"event":"view_item"', '"event":"view_item_list"', dierapothekerPage());

    expect($this->adapter->extract($this->url, $html)->snapshot?->packSize)->toBeNull();
});

test('an out-of-stock product is reported as such', function (): void {
    $result = $this->adapter->extract($this->url, dierapothekerPage(availability: 'https://schema.org/OutOfStock'));

    expect($result->snapshot?->inStock)->toBeFalse();
});

test('a page without the product data element fails instead of falling back to the tier price', function (): void {
    $html = str_replace('id="product-data"', 'id="something-else"', dierapothekerPage());

    $result = $this->adapter->extract($this->url, $html);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('dierapotheker_no_product_data');
});

test('a variant from the next analytics event is not read as this page\'s', function (): void {
    // The view_item event states no variant, and the list event that
    // follows carries another product's.
    $html = str_replace(
        [',"item_variant":"Navulling 3 x 48 ml"', '</script>'],
        ['', 'dataLayer.push({"event":"view_item_list","items":[{"item_variant":"Navulling 1 x 48 ml"}]});</script>'],
        dierapothekerPage(),
    );

    expect($html)->toContain('"event":"view_item"')
        ->and($this->adapter->extract($this->url, $html)->snapshot?->packSize)->toBeNull();
});

test('a page describing a different article than the URL is refused', function (): void {
    $result = $this->adapter->extract('https://www.dierapotheker.nl/ander-product/1234/', dierapothekerPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('dierapotheker_product_mismatch');
});
