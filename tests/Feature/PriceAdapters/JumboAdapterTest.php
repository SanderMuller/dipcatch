<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\JumboAdapter;
use App\Support\PackSize;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Trimmed replica of jumbo.com's server-rendered price component
 * (observed 2026-08-31 on /producten/... pages).
 */
function jumboPriceComponent(string $screenreader, string $whole, string $fractional): string
{
    return <<<HTML
<div class="jum-price prominent product-price" data-testid="product-price">
  <div class="current-price">
    <div class="screenreader-only"><!--[-->{$screenreader}<!--]--></div>
    <span class="whole" aria-hidden="true">{$whole}</span><span class="fractional" aria-hidden="true">{$fractional}</span>
  </div>
</div>
HTML;
}

beforeEach(function (): void {
    $this->adapter = new JumboAdapter();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('skips when the URL host is not jumbo.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'HiPRO Protein Drink Mango 300ml',
        'offers' => [
            '@type' => 'AggregateOffer',
            'highPrice' => 14.34,
            'lowPrice' => 14.34,
            'offerCount' => 99,
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://www.jumbo.com/producten/hipro-494984DSL', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('reads an enabled primary add-to-cart button as in stock when JSON-LD omits availability', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Fanta Cassis 1,5 L',
        'offers' => [
            '@type' => 'AggregateOffer',
            'highPrice' => 2.85,
            'lowPrice' => 2.85,
            'offerCount' => 99,
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);
    $html = str_replace(
        '<body></body>',
        '<body><div class="product-panel-info">'
            . jumboPriceComponent('Prijs: € 2,85', '2', '85')
            . '<button aria-label="Toevoegen aan mandje Fanta Cassis 1,5 L">Toevoegen aan mandje</button>'
            . '</div></body>',
        withJsonLd($json),
    );

    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/fanta-cassis-1,5-l-428446FLS', $html)
        ->snapshot;

    expect($snapshot?->inStock)->toBeTrue()
        ->and($snapshot?->stockSignal)->toBe('jumbo:add-to-cart');
});

test('falls back to the price component screenreader text when JSON-LD is missing', function (): void {
    $html = '<html><head>'
        . '<meta content="Milner 35+ Jong Kaas Stuk 450 g" property="og:title">'
        . '<meta content="https://www.jumbo.com/dam-images/kaas.png" property="og:image">'
        . '</head><body>'
        . jumboPriceComponent('Prijs: € 7,59', '7', '59')
        . '</body></html>';

    $result = $this->adapter->extract('https://www.jumbo.com/producten/milner-194089STK', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('7.59')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Milner 35+ Jong Kaas Stuk 450 g')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.jumbo.com/dam-images/kaas.png');
});

test('rebuilds the price from whole + fractional spans when the screenreader div is empty', function (): void {
    $html = '<html><body>'
        . '<h1>HiPRO Protein Drink</h1>'
        . jumboPriceComponent('', '14', '34')
        . '</body></html>';

    $result = $this->adapter->extract('https://jumbo.com/producten/hipro-494984DSL', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34')
        ->and($result->snapshot?->title)->toBe('HiPRO Protein Drink');
});

test('ignores card prices elsewhere on the page — only the product-price component counts', function (): void {
    $html = '<html><body>'
        . '<div class="jum-price right price"><div class="current-price"><div class="screenreader-only">Prijs: € 2,39</div></div></div>'
        . jumboPriceComponent('Prijs: € 14,34', '14', '34')
        . '</body></html>';

    $result = $this->adapter->extract('https://jumbo.com/producten/hipro-494984DSL', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34');
});

test('failed when jumbo page has neither JSON-LD nor the price component', function (): void {
    $result = $this->adapter->extract('https://jumbo.com/producten/broken', '<html><body><p>x</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jumbo_extraction_failed');
});

test('extracts a fixed-total bundle from the primary product', function (): void {
    CarbonImmutable::setTestNow('2026-09-12 12:00:00 Europe/Amsterdam');
    $html = File::get(base_path('tests/Fixtures/bundle-prices/jumbo-fixed-total.html'));

    $result = $this->adapter->extract('https://www.jumbo.com/producten/fanta-cassis-1,5-l-428446FLS', $html);

    expect($result->snapshot?->price)->toBe('2.85')
        ->and($result->snapshot?->trackedPrice())->toBe('2.00')
        ->and($result->snapshot?->bundleOffer?->quantity)->toBe(2)
        ->and($result->snapshot?->bundleOffer?->totalPrice)->toBe('4.00')
        ->and($result->snapshot?->bundleOfferAuthoritative)->toBeTrue()
        ->and($result->snapshot?->promotionWindow?->label)->toBe('2 voor 4,00');
});

test('extracts a bundle when minified markup joins the promotion month to following text', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00 Europe/Amsterdam');
    $html = '<html><body><div class="product-panel-info">'
        . jumboPriceComponent('Prijs: € 2,85', '2', '85')
        . '<div data-testautomation="pdp-promotion">'
        . '<span data-testid="promotion-tag">2 voor 4,00</span>'
        . '<div data-testid="product-communication"><h6>Geldig van wo 9 t/m di 15 sep</h6><p>Bij boodschappen bestellen geldt de aanbieding.</p></div>'
        . '</div></div></body></html>';

    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/fanta-cassis-1,5-l-428446FLS', $html)
        ->snapshot;

    expect($snapshot?->trackedPrice())->toBe('2.00')
        ->and($snapshot?->bundleOffer?->quantity)->toBe(2)
        ->and($snapshot?->bundleOffer?->totalPrice)->toBe('4.00')
        ->and($snapshot?->promotionWindow?->endsAt->toDateString())->toBe('2026-09-15');
});

test('extracts a free-item bundle from the primary product', function (): void {
    CarbonImmutable::setTestNow('2026-09-12 12:00:00 Europe/Amsterdam');
    $html = File::get(base_path('tests/Fixtures/bundle-prices/jumbo-free-item.html'));

    $result = $this->adapter->extract('https://www.jumbo.com/producten/knorr-good-noodles-kip-70-g-752107PAK', $html);

    expect($result->snapshot?->trackedPrice())->toBe('0.60')
        ->and($result->snapshot?->bundleOffer?->totalPrice)->toBe('1.19');
});

test('authoritatively ignores related-product promotions', function (): void {
    $html = File::get(base_path('tests/Fixtures/bundle-prices/jumbo-no-offer.html'));

    $result = $this->adapter->extract('https://www.jumbo.com/producten/lays-control', $html);

    expect($result->snapshot?->price)->toBe('1.99')
        ->and($result->snapshot?->bundleOffer)->toBeNull()
        ->and($result->snapshot?->bundleOfferAuthoritative)->toBeTrue();
});

test('rejects bundle dates that cannot form a promotion window and records a diagnostic', function (): void {
    $html = '<html><body><div class="product-panel-info">'
        . jumboPriceComponent('Prijs: € 2,85', '2', '85')
        . '<div data-testautomation="pdp-promotion">'
        . '<span data-testid="promotion-tag">2 voor 4,00</span>'
        . '<span data-testid="product-communication">Geldig van ma 99 sep t/m zo 100 sep</span>'
        . '</div></div></body></html>';

    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/fanta-cassis-1,5-l-428446FLS', $html)
        ->snapshot;

    expect($snapshot?->trackedPrice())->toBe('2.85')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue()
        ->and($snapshot?->raw['bundle_diagnostic'] ?? null)->toBe('invalid_promotion_window');
});

test('a page with no promotion block keeps the period the JSON-LD stated', function (): void {
    $validUntil = CarbonImmutable::now()->addDays(7)->toDateString();
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Milner 35+ Jong Kaas Stuk 450 g',
        'offers' => [
            '@type' => 'Offer',
            'price' => '7.59',
            'priceCurrency' => 'EUR',
            'priceValidUntil' => $validUntil,
        ],
    ], JSON_THROW_ON_ERROR);

    $html = '<html><head><script type="application/ld+json">' . $json . '</script></head><body>'
        . '<div class="product-panel-info">' . jumboPriceComponent('Prijs: € 7,59', '7', '59') . '</div>'
        . '</body></html>';

    $result = $this->adapter->extract('https://www.jumbo.com/producten/milner-194089STK', $html);

    expect($result->snapshot?->promotionWindow?->endsAt?->toDateString())->toBe($validUntil)
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a blank h1 falls back to the shop name, not to nothing', function (): void {
    $html = '<html><body><h1>   </h1>'
        . jumboPriceComponent('Prijs: € 7,59', '7', '59')
        . '</body></html>';

    $result = $this->adapter->extract('https://www.jumbo.com/producten/milner-194089STK', $html);

    expect($result->snapshot?->title)->toBe('Jumbo product');
});

/**
 * A product panel as jumbo.com serves it (observed 2026-10-17): the title,
 * the size under it, and the price with its price per unit.
 */
function jumboShelfPage(string $title, string $subtitle, string $price, string $perUnit): string
{
    [$whole, $fractional] = explode(',', $price);
    $json = json_encode([
        '@type' => 'Product',
        'name' => $title,
        'offers' => ['@type' => 'AggregateOffer', 'highPrice' => (float) str_replace(',', '.', $price), 'lowPrice' => (float) str_replace(',', '.', $price), 'offerCount' => 99, 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);
    $component = str_replace(
        '</div>' . "\n" . '</div>',
        "</div>\n  <div class=\"price-per-unit\"><div class=\"screenreader-only\"><!--[-->{$perUnit}<!--]--></div></div>\n</div>",
        jumboPriceComponent("Prijs: € {$price}", $whole, $fractional),
    );

    return str_replace(
        '<body></body>',
        '<body><div class="product-panel-info">'
            . "<h1 data-testid=\"product-title\">{$title}</h1>"
            . "<div class=\"product-description\"><span data-testid=\"product-subtitle\">{$subtitle}</span></div>"
            . $component
            . '</div></body>',
        withJsonLd($json),
    );
}

test('reads the size the price per kilo confirms as the whole pack, not one of the counted items', function (): void {
    // "8 Stuks 200 g" read as eight packs of 200 g: 1.6 kg for € 1,95.
    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/jumbo-double-chocolate-cookies-8-stuks-200-g-523104PAK', jumboShelfPage('Jumbo Double Chocolate Cookies 8 Stuks 200 g', '200 g', '1,95', '€ 9,75 per kilo'))
        ->snapshot;

    expect($snapshot?->packSize)->toBe('200 g')
        ->and($snapshot?->packSizeIsWholePack)->toBeTrue()
        ->and(PackSize::resolve($snapshot?->packSize, $snapshot->packSizeAuthoritative, $snapshot->title, $snapshot->packSizeIsWholePack)?->quantity)->toBe(200.0);
});

test('takes the size from the title when the page states none under it', function (string $title, string $price, string $perUnit, string $expected): void {
    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/x-1PAK', jumboShelfPage($title, '', $price, $perUnit))
        ->snapshot;

    expect($snapshot?->packSize)->toBe($expected);
})->with([
    // The unit price is rounded to cents: 1,19 at 7,73 per kilo is 153.9 g.
    'a weight' => ['Oreo Original koekjes 154g', '1,19', '€ 7,73 per kilo', '154 g'],
    'a multipack of drinks' => ['Heineken Pilsener Bier 12 x 250ml', '13,99', '€ 4,66 per liter', '3000 ml'],
]);

test('states no size when the unit price is per piece, or agrees with no size on the page', function (string $subtitle, string $perUnit): void {
    $snapshot = $this->adapter
        ->extract('https://www.jumbo.com/producten/x-1PAK', jumboShelfPage('Jumbo Cookies 8 Stuks 200 g', $subtitle, '1,95', $perUnit))
        ->snapshot;

    expect($snapshot?->packSize)->toBeNull()
        ->and($snapshot?->packSizeIsWholePack)->toBeFalse();
})->with([
    'per piece' => ['200 g', '€ 0,24 per stuk'],
    'a unit price for another size' => ['200 g', '€ 4,88 per kilo'],
]);
