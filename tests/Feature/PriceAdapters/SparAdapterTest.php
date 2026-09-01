<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\SparAdapter;

beforeEach(function (): void {
    $this->adapter = new SparAdapter();
});

test('skips a host that is not spar.nl', function (): void {
    expect($this->adapter->extract('https://other.com/p/1', sparPage())->isSkip())->toBeTrue();
});

test('adds the pack size the JSON-LD omits, so SPAR shops get a unit price', function (): void {
    $result = $this->adapter->extract('https://www.spar.nl/lay-s-chips-naturel-9183397/', sparPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.45')
        ->and($result->snapshot?->title)->toBe("Lay's chips naturel")
        ->and($result->snapshot?->gtin)->toBe('8710398526014')
        ->and($result->snapshot?->packSize)->toBe('200 Gram')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue();
});

test('a related product card cannot donate its size', function (): void {
    // The page lists other products at 125 Gram; only the offer subtitle counts.
    expect($this->adapter->extract('https://www.spar.nl/x-1/', sparPage())->snapshot?->packSize)
        ->toBe('200 Gram');
});

test('a page without the subtitle still prices, just without a size', function (): void {
    $result = $this->adapter->extract('https://www.spar.nl/x-1/', sparPage(packSize: null));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.45')
        ->and($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('a page without JSON-LD fails with a SPAR-specific reason', function (): void {
    $result = $this->adapter->extract('https://www.spar.nl/x-1/', '<html><body>nothing</body></html>');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('spar_extraction_failed');
});
