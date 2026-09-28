<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\ZooplusPackSize;

/**
 * A page state with one named variant, trimmed to the fields the size is
 * read from.
 *
 * @param  array<string, mixed>  $unit
 */
function zooplusStatePage(float $price, array $unit): string
{
    $state = json_encode(['props' => ['pageProps' => ['pageLevelProps' => [
        'activeVariantFromUrl' => '1000.7',
        'productDetails' => ['product' => ['articleVariants' => [[
            'variantId' => 7,
            'offers' => [['price' => ['currentPrice' => ['value' => $price], 'discounts' => []], 'unit' => $unit]],
        ]]]],
    ]]]], JSON_THROW_ON_ERROR);

    return '<html><body><script id="__NEXT_DATA__" type="application/json">' . $state . '</script></body></html>';
}

test('one pack reads as one size whatever its price', function (float $price, float $rate): void {
    // 9.99 / 24.98 and 3.49 / 8.73 are both 400 g within the rate's cents.
    $html = zooplusStatePage($price, ['unitPriceRaw' => $rate, 'unitQuantity' => 0.4, 'unitName' => 'kg', 'unitNameRaw' => 'kg']);

    expect(ZooplusPackSize::read('https://www.zooplus.nl/shop/p/1000', $html, (string) $price))->toBe('0.4 kg');
})->with([
    'at 9.99' => [9.99, 24.98],
    'at 3.49' => [3.49, 8.73],
]);

test('a size the page rounded is read to the gram, not to the rate cent', function (): void {
    // 0.375 kg is stated as 0.38; 5.99 / 15.97 is 0.37508.
    $html = zooplusStatePage(5.99, ['unitPriceRaw' => 15.97, 'unitQuantity' => 0.38, 'unitName' => 'kg', 'unitNameRaw' => 'kg']);

    expect(ZooplusPackSize::read('https://www.zooplus.nl/shop/p/1000', $html, '5.99'))->toBe('0.375 kg');
});

test('a piece is read in any language the page names it', function (): void {
    $html = zooplusStatePage(27.99, ['unitPriceRaw' => 27.99, 'unitQuantity' => 1, 'unitName' => 'Stück', 'unitNameRaw' => 'piece']);

    expect(ZooplusPackSize::read('https://www.zooplus.de/shop/p/1000', $html, '27.99'))->toBe('1 stuks');
});

test('a unit name holding a number is not read as a size', function (): void {
    // "4 100g" would parse as 100 g.
    $html = zooplusStatePage(4.0, ['unitPriceRaw' => 1.0, 'unitQuantity' => 4, 'unitName' => '100g', 'unitNameRaw' => '100g']);

    expect(ZooplusPackSize::read('https://www.zooplus.nl/shop/p/1000', $html, '4.00'))->toBeNull();
});
