<?php declare(strict_types=1);

use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\UnitPriceSize;

/**
 * @return array<string, mixed>
 */
function offerWithUnitPrice(mixed $rate = 416.6, string $unitCode = 'LTR', float $per = 1.0): array
{
    return [
        '@type' => 'Offer',
        'price' => 59.99,
        'priceCurrency' => 'EUR',
        'priceSpecification' => [
            ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/SalePrice', 'price' => 59.99],
            [
                '@type' => 'UnitPriceSpecification',
                'price' => 50.99,
                'validForMemberTier' => ['@type' => 'MemberProgramTier', 'name' => 'autoshipment'],
            ],
            [
                '@type' => 'UnitPriceSpecification',
                'priceType' => 'https://schema.org/UnitPrice',
                'price' => $rate,
                'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => $per, 'unitCode' => $unitCode],
            ],
        ],
    ];
}

test('recovers the pack size the unit price implies', function (): void {
    expect(UnitPriceSize::from(offerWithUnitPrice(), '59.99'))->toBe('144 ml');
});

test('a member or sale price is never read as the rate', function (): void {
    // Both sit before the UnitPrice entry; 59.99/59.99 would be "1 ml".
    expect(UnitPriceSize::from(offerWithUnitPrice(), '59.99'))->not->toBe('1 ml');
});

test('reads a rate given per 100 grams', function (): void {
    expect(UnitPriceSize::from(offerWithUnitPrice(rate: 2.5, unitCode: 'GRM', per: 100.0), '5.00'))->toBe('200 g');
});

test('reads a rate given per piece', function (): void {
    expect(UnitPriceSize::from(offerWithUnitPrice(rate: 0.5, unitCode: 'H87'), '3.00'))->toBe('6 stuks');
});

test('a single unwrapped specification is read too', function (): void {
    $offer = [
        '@type' => 'Offer',
        'priceSpecification' => [
            '@type' => 'UnitPriceSpecification',
            'priceType' => 'https://schema.org/UnitPrice',
            'price' => 10.0,
            'referenceQuantity' => ['value' => 1, 'unitCode' => 'KGM'],
        ],
    ];

    expect(UnitPriceSize::from($offer, '2.50'))->toBe('250 g');
});

test('a unit this app cannot normalize yields no size', function (): void {
    expect(UnitPriceSize::from(offerWithUnitPrice(unitCode: 'MTR'), '59.99'))->toBeNull();
});

test('a rate of zero yields no size instead of dividing by it', function (): void {
    expect(UnitPriceSize::from(offerWithUnitPrice(rate: 0), '59.99'))->toBeNull();
});

test('an offer without a unit price yields no size', function (): void {
    expect(UnitPriceSize::from(['@type' => 'Offer', 'price' => 1.0], '1.00'))->toBeNull();
});

test('the JSON-LD adapter reports a derived size as authoritative', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'FELIWAY Classic',
        'url' => 'https://shop.test/p/1',
        'offers' => offerWithUnitPrice(),
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', withJsonLd($json));

    expect($result->snapshot?->packSize)->toBe('144 ml')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue();
});

test('an offer without a unit price leaves the title fallback available', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Chips 200 g',
        'url' => 'https://shop.test/p/1',
        'offers' => ['@type' => 'Offer', 'price' => '2.45', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', withJsonLd($json));

    expect($result->snapshot?->packSize)->toBeNull()
        ->and($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});
