<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\VikingAdapter;

/**
 * A Viking product page as it reads on 2026-11-23: the offer's own price is
 * the 6+ tier, every price is without VAT, and the headline price is printed
 * beside the same price with VAT.
 *
 * @param  list<array{from: int, price: float}>  $tiers
 */
function vikingPage(float $offerPrice, array $tiers, string $alternative = '€ 6,53 Incl. btw'): string
{
    $specs = array_map(static fn (array $tier): array => [
        '@type' => 'UnitPriceSpecification',
        'price' => $tier['price'],
        'priceCurrency' => 'EUR',
        'valueAddedTaxIncluded' => false,
        'eligibleQuantity' => ['@type' => 'QuantitativeValue', 'minValue' => $tier['from'], 'unitCode' => 'C62'],
    ], $tiers);

    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Twix Minis mini-chocoladereep 15 stuks à 20 g',
        'offers' => ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'price' => $offerPrice, 'availability' => 'https://schema.org/InStock', 'priceSpecification' => $specs],
    ], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $json . '</script></head><body>'
        . '<div id="productPrice_1284319"> € ' . number_format($offerPrice, 2, ',', '') . '</div>'
        . '<div id="productPriceAlternative_1284319"> ' . $alternative . '</div>'
        . '</body></html>';
}

it('reads the price of one item, with the VAT the page applies', function (float $offerPrice, string $alternative, string $expected): void {
    $page = vikingPage($offerPrice, [['from' => 1, 'price' => 7.49], ['from' => 3, 'price' => 6.79], ['from' => 6, 'price' => $offerPrice]], $alternative);

    $result = new VikingAdapter()->extract('https://www.vikingdirect.nl/nl/twix-chocoladereep-pak-van-15-p-1284319', $page);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe($expected);
})->with([
    '9% on food' => [5.99, '€ 6,53 Incl. btw', '8.16'],
    '21% on the rest' => [5.99, '€ 7,25 Incl. btw', '9.06'],
]);

it('adds the VAT to the offer price when the offer has no tier for one item', function (): void {
    $result = new VikingAdapter()->extract('https://www.vikingdirect.nl/nl/twix-chocoladereep-pak-van-15-p-1284319', vikingPage(5.99, [['from' => 6, 'price' => 5.99]]));

    expect($result->snapshot?->price)->toBe('6.53');
});

it('fails rather than store a price without VAT', function (string $alternative): void {
    $result = new VikingAdapter()->extract('https://www.vikingdirect.nl/nl/twix-chocoladereep-pak-van-15-p-1284319', vikingPage(5.99, [['from' => 1, 'price' => 7.49]], $alternative));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('viking_vat_unreadable');
})->with([
    'no price with VAT' => [''],
    'a price no Dutch rate explains' => ['€ 7,00 Incl. btw'],
    'the other price is without VAT' => ['€ 5,50 Excl. btw'],
]);

it('skips another shop', function (): void {
    expect(new VikingAdapter()->extract('https://www.etos.nl/p/1', vikingPage(5.99, []))->isSkip())->toBeTrue();
});
