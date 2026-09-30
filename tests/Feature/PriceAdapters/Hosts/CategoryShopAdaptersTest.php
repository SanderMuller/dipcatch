<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\Hosts\ExpertAdapter;
use App\PriceAdapters\Hosts\HuboAdapter;
use App\PriceAdapters\Hosts\IntertoysAdapter;
use App\PriceAdapters\Hosts\MediaMarktAdapter;
use App\PriceAdapters\Hosts\MegekkoAdapter;
use App\PriceAdapters\Hosts\PrenatalAdapter;
use App\PriceAdapters\Hosts\ToolstationAdapter;
use App\PriceAdapters\ShopAdapter;

function categoryFixture(string $key): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/category-shops/{$key}.html"));
}

/**
 * Trimmed from the live pages on 2026-09-30: the JSON-LD and OpenGraph tags
 * only, reviews removed. Prices checked against what the page shows a
 * shopper (MediaMarkt's is a marketplace seller's offer).
 */
test('each marketed shop reads its own product page', function (string $class, string $key, string $url, string $title, string $price): void {
    /** @var ShopAdapter $adapter */
    $adapter = new $class();
    $result = app(AdapterResolver::class)->resolve($url, categoryFixture($key));

    expect($adapter->key())->toBe($key)
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->adapterKey)->toBe($key)
        ->and($result->snapshot?->title)->toBe($title)
        ->and($result->snapshot?->price)->toBe($price)
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($adapter->extract('https://other.test/p/1', categoryFixture($key))->isSkip())->toBeTrue();
})->with([
    'MediaMarkt' => [MediaMarktAdapter::class, 'mediamarkt', 'https://www.mediamarkt.nl/nl/product/_apple-airpods-2nd-generation-oordopjes-wit-108253882.html', 'APPLE AirPods (2nd generation) In-ear Oordopjes Wit', '99.95'],
    'Expert' => [ExpertAdapter::class, 'expert', 'https://www.expert.nl/wd-elements-portable-5tb-372606637', 'WD Elements Portable 5TB', '209.00'],
    'Megekko' => [MegekkoAdapter::class, 'megekko', 'https://www.megekko.nl/product/5093/293096/SSD-M-2/Samsung-990-PRO-2TB-M-2-SSD', 'Samsung 990 PRO 2TB M.2 SSD', '359.00'],
    'Intertoys' => [IntertoysAdapter::class, 'intertoys', 'https://www.intertoys.nl/lego-city-toeristische-rode-dubbeldekker-60407', 'LEGO City toeristische rode dubbeldekker 60407', '24.99'],
    'Prénatal' => [PrenatalAdapter::class, 'prenatal', 'https://www.prenatal.nl/difrax-fopspeen-lovi-0-6-maanden-ecru-6163696/', 'Difrax fopspeen LOVI 0-6 maanden', '10.99'],
    'Hubo' => [HuboAdapter::class, 'hubo', 'https://www.hubo.nl/products/hubo-muur-en-plafondverf-extra-mat-wit-10l', 'Hubo muur- en plafondverf extra mat wit 10L', '23.09'],
    // The price incl. VAT; the page prints "Excl. btw € 28,63" beside it.
    'Toolstation' => [ToolstationAdapter::class, 'toolstation', 'https://www.toolstation.nl/dynaplus-universele-schroeven-platkop-deeldraad-verzinkt/p13052', 'Dynaplus universele schroeven platkop deeldraad verzinkt', '34.64'],
]);

test('a page an owned host states only in OpenGraph still reads', function (): void {
    $html = '<html><head><meta property="og:title" content="Verf 5L"><meta property="og:price:amount" content="19.99"><meta property="og:price:currency" content="EUR"></head></html>';

    $result = new HuboAdapter()->extract('https://www.hubo.nl/products/verf-5l', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99');
});

test('a Shopify variant page on an owned host offers the chooser', function (): void {
    $html = (string) file_get_contents(base_path('tests/Fixtures/scraper/shopify_prometeus_variants.html'));

    expect(new HuboAdapter()->extract('https://www.hubo.nl/products/fit-co-protein-bar-10-x-55-g', $html)->isAmbiguous())->toBeTrue();
});

test('an ambiguous or failed JSON-LD answer is final, even beside readable OpenGraph', function (string $case): void {
    $og = '<meta property="og:title" content="Kettle"><meta property="og:price:amount" content="19.99"><meta property="og:price:currency" content="EUR">';
    $entity = match ($case) {
        'ambiguous' => ['@type' => 'ProductGroup', 'name' => 'Kettle', 'hasVariant' => [
            ['@type' => 'Product', 'name' => 'Kettle red', 'sku' => 'R', 'offers' => ['@type' => 'Offer', 'price' => '20.00', 'priceCurrency' => 'EUR']],
            ['@type' => 'Product', 'name' => 'Kettle blue', 'sku' => 'B', 'offers' => ['@type' => 'Offer', 'price' => '22.00', 'priceCurrency' => 'EUR']],
        ]],
        'failed' => ['@type' => 'Product', 'name' => 'Kettle'],
    };
    $html = '<html><head>' . $og . '<script type="application/ld+json">' . json_encode($entity, JSON_THROW_ON_ERROR) . '</script></head></html>';

    $result = new ExpertAdapter()->extract('https://www.expert.nl/kettle-1', $html, new AdapterContext());

    expect($case === 'ambiguous' ? $result->isAmbiguous() : $result->isFailed())->toBeTrue();
})->with(['ambiguous', 'failed']);

test('a page only the heuristic reader could price stops reading on an owned host', function (): void {
    $html = '<html><body><h1>Kettle</h1><span class="price">€ 19,99</span></body></html>';

    $result = app(AdapterResolver::class)->resolve('https://www.expert.nl/kettle-1', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('expert_extraction_failed');
});
