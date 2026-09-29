<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\Hosts\ZooplusAdapter;

beforeEach(function (): void {
    $this->adapter = new ZooplusAdapter();
});

test('skips when host does not match', function (): void {
    $result = $this->adapter->extract('https://example.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('extracts the active variant price from a real zooplus page', function (): void {
    $html = (string) file_get_contents(base_path('tests/Fixtures/scraper/zooplus_feliway.html'));

    $result = $this->adapter->extract(
        'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.19',
        $html,
    );

    // Variant .19 = "Voordeelset: 3 navulflessen à 48 ml" at €58,99.
    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('58.99')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to first reducedPriceAmount when no active variant class present', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1 data-zta="ProductTitle__Title">Single product</h1>
  <span class="z-product-price__amount" data-zta="reducedPriceAmount">€ 12,34</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.zooplus.de/shop/foo', $html);

    expect($result->snapshot?->price)->toBe('12.34')
        ->and($result->snapshot?->title)->toBe('Single product');
});

test('matches all zooplus country TLDs and Bitiba sister shops', function (string $url): void {
    $html = '<span data-zta="reducedPriceAmount">€ 9,99</span>';
    $result = $this->adapter->extract($url, $html);

    expect($result->isSuccess())->toBeTrue();
})->with([
    'nl' => ['https://www.zooplus.nl/shop/foo'],
    'de' => ['https://www.zooplus.de/shop/foo'],
    'be' => ['https://www.zooplus.be/shop/foo'],
    'com' => ['https://www.zooplus.com/shop/foo'],
    'uk' => ['https://www.zooplus.co.uk/shop/foo'],
    'bitiba-nl' => ['https://www.bitiba.nl/shop/foo'],
    'bitiba-de' => ['https://www.bitiba.de/shop/foo'],
]);

test('reads a sterling price on the UK shop', function (): void {
    $html = '<span data-zta="reducedPriceAmount">£ 9.99</span>';
    $result = $this->adapter->extract('https://www.zooplus.co.uk/shop/foo', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('9.99')
        ->and($result->snapshot?->currency)->toBe('GBP');
});

test('an unanswered variant question is put to the user, not answered by the CSS fallback', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'FELIWAY Classic',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Startpakket',
                'sku' => '169589.10',
                'url' => 'https://www.zooplus.nl/shop/x/169589?activeVariant=169589.10',
                'offers' => ['@type' => 'Offer', 'price' => '26.99', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Voordeelverpakking',
                'sku' => '169589.19',
                'url' => 'https://www.zooplus.nl/shop/x/169589?activeVariant=169589.19',
                'offers' => ['@type' => 'Offer', 'price' => '59.99', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    // The markup also carries a price the CSS fallback would happily read.
    $html = '<html><body><script type="application/ld+json">' . $json . '</script>'
        . '<h1 data-zta="ProductTitle__Title">FELIWAY Classic</h1>'
        . '<span data-zta="reducedPriceAmount">€ 26,99</span></body></html>';

    $result = $this->adapter->extract('https://www.zooplus.nl/shop/x/169589', $html);

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->variants)->toHaveCount(2)
        ->and($result->snapshot)->toBeNull();

    // Answering it resolves to that variant, not to the fallback price.
    $answered = $this->adapter->extract(
        'https://www.zooplus.nl/shop/x/169589',
        $html,
        new AdapterContext(variantKey: '169589.19'),
    );

    expect($answered->isSuccess())->toBeTrue()
        ->and($answered->snapshot?->price)->toBe('59.99');
});

test('a blank og:title says nothing, so the fallback name stands', function (): void {
    $html = <<<'HTML'
<html><head><meta property="og:title" content="   "></head><body>
  <span class="z-product-price__amount" data-zta="reducedPriceAmount">€ 12,34</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.zooplus.de/shop/foo', $html);

    expect($result->snapshot?->title)->toBe('Zooplus product');
});

test('the scoped product h1 wins over og:title, and a bare h1 is not read', function (): void {
    $html = <<<'HTML'
<html><head><meta property="og:title" content="Og name"></head><body>
  <h1>Related products</h1>
  <h1 data-zta="ProductTitle__Title">Product name</h1>
  <span class="z-product-price__amount" data-zta="reducedPriceAmount">€ 12,34</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.zooplus.de/shop/foo', $html);

    expect($result->snapshot?->title)->toBe('Product name');
});

/**
 * A bitiba page trimmed to what the pack size is read from, as published on
 * 2026-09-28: a 10 kg bag on sale at 28.79, regular 31.99. The JSON-LD pairs
 * the sale price with the regular price's rate of 3.20 per kilo.
 *
 * @param  array<string, mixed>  $unit
 */
function bitibaSalePage(array $unit = [], ?string $activeVariant = '397805.33', float $otherPrice = 13.19): string
{
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'IAMS Advanced Nutrition Adult met Kip',
        'sku' => '397805.33',
        'offers' => [
            '@type' => 'Offer',
            'price' => 28.79,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'priceSpecification' => [
                ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => 31.99],
                ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/SalePrice', 'price' => 28.79],
                [
                    '@type' => 'UnitPriceSpecification',
                    'priceType' => 'https://schema.org/UnitPrice',
                    'price' => 3.2,
                    'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'KGM'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $variant = static fn (int $id, float $price, array $unit, array $discounts = []): array => [
        'variantId' => $id,
        'offers' => [[
            'price' => ['currency' => 'EUR', 'currentPrice' => ['value' => $price], 'discounts' => $discounts],
            'unit' => $unit,
        ]],
    ];

    $state = json_encode(['props' => ['pageProps' => ['pageLevelProps' => [
        'activeVariantFromUrl' => $activeVariant,
        'productDetails' => ['product' => ['articleVariants' => [
            $variant(32, $otherPrice, ['unitPriceRaw' => 4.4, 'unitQuantity' => 3, 'unitName' => 'kg', 'unitNameRaw' => 'kg']),
            $variant(33, 31.99, [...['unitPriceRaw' => 3.2, 'unitQuantity' => 10, 'unitName' => 'kg', 'unitNameRaw' => 'kg'], ...$unit], [
                ['discountedPriceRaw' => 28.79, 'label' => '-10%', 'type' => 'ABD'],
            ]),
        ]]],
    ]]]], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body>'
        . '<script id="__NEXT_DATA__" type="application/json">' . $state . '</script></body></html>';
}

test('a bag on sale keeps the pack size its page states, not the one its JSON-LD implies', function (): void {
    // 28.79 / 3.20 would read the 10 kg bag as 9 kg.
    $result = $this->adapter->extract('https://www.bitiba.nl/shop/katten/iams_adult/397805?activeVariant=397805.33', bitibaSalePage());

    expect($result->snapshot?->price)->toBe('28.79')
        ->and($result->snapshot?->packSize)->toBe('10 kg')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue();
});

test('a stated size rounded to two decimals gives way to the exact one', function (): void {
    // The page states 0.14 l for three 48 ml bottles; 58.99 / 409.65 is 0.144.
    $html = (string) file_get_contents(base_path('tests/Fixtures/scraper/zooplus_feliway.html'));

    $result = $this->adapter->extract(
        'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.19',
        $html,
    );

    expect($result->snapshot?->packSize)->toBe('0.144 l');
});

test('a page state that contradicts its own price and rate states no size', function (): void {
    $result = $this->adapter->extract(
        'https://www.bitiba.nl/shop/katten/iams_adult/397805?activeVariant=397805.33',
        bitibaSalePage(unit: ['unitQuantity' => 12]),
    );

    expect($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('the page state of another variant is not read for this one', function (): void {
    // Variant 32 does not sell at 28.79, so its 3 kg is not this pack.
    $result = $this->adapter->extract(
        'https://www.bitiba.nl/shop/katten/iams_adult/397805?activeVariant=397805.32',
        bitibaSalePage(activeVariant: '397805.32'),
    );

    expect($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});

test('a variant the user chose is read even when the page names none', function (): void {
    $result = $this->adapter->extract(
        'https://www.bitiba.nl/shop/katten/iams_adult/397805',
        bitibaSalePage(activeVariant: null, otherPrice: 28.79),
        new AdapterContext(variantKey: '397805.33'),
    );

    expect($result->snapshot?->packSize)->toBe('10 kg');
});

test('a bare variant id in the URL names the variant', function (): void {
    $result = $this->adapter->extract(
        'https://www.bitiba.nl/shop/katten/iams_adult/397805?activeVariant=33',
        bitibaSalePage(activeVariant: null, otherPrice: 28.79),
    );

    expect($result->snapshot?->packSize)->toBe('10 kg');
});

test('with no variant named, the only variant selling at the tracked price is read', function (): void {
    $result = $this->adapter->extract('https://www.bitiba.nl/shop/katten/iams_adult/397805', bitibaSalePage(activeVariant: null));

    expect($result->snapshot?->packSize)->toBe('10 kg');
});

test('with no variant named and two selling at the tracked price, no size is read', function (): void {
    $result = $this->adapter->extract(
        'https://www.bitiba.nl/shop/katten/iams_adult/397805',
        bitibaSalePage(activeVariant: null, otherPrice: 28.79),
    );

    expect($result->snapshot?->packSizeAuthoritative)->toBeFalse();
});
