<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\JsonLdAdapter;

test('skips when no application/ld+json script is present', function (): void {
    $result = new JsonLdAdapter()->extract('https://x.test', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('extracts basic Product + Shop', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Sony WH-1000XM5',
        'image' => 'https://shop.test/img.jpg',
        'offers' => [
            '@type' => 'Shop',
            'price' => '289.00',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->title)->toBe('Sony WH-1000XM5')
        ->and($result->snapshot?->price)->toBe('289.00')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://shop.test/img.jpg');
});

test('handles AggregateOffer using lowPrice', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Headphones',
        'offers' => [
            '@type' => 'AggregateOffer',
            'lowPrice' => 199.50,
            'highPrice' => 299.00,
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    $snapshot = $result->snapshot;
    assert($snapshot !== null);
    expect((float) $snapshot->price)->toBe(199.50);
});

test('handles array of Offers', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Demo',
        'offers' => [
            ['@type' => 'Shop', 'price' => '50.00', 'priceCurrency' => 'EUR'],
            ['@type' => 'Shop', 'price' => '40.00', 'priceCurrency' => 'EUR'],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('50.00');
});

test('handles top-level @graph', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'WebSite', 'name' => 'Demo Shop'],
            [
                '@type' => 'Product',
                'name' => 'Widget',
                'offers' => ['@type' => 'Shop', 'price' => '12.99', 'priceCurrency' => 'USD'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('12.99')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('handles price as a number (not string)', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Numeric',
        'offers' => ['@type' => 'Shop', 'price' => 99.99, 'priceCurrency' => 'GBP'],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->snapshot?->price)->toBe('99.99');
});

test('handles price nested under priceSpecification.price', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Nested',
        'offers' => [
            '@type' => 'Shop',
            'priceSpecification' => [
                'price' => 45.50,
                'priceCurrency' => 'EUR',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    $snapshot = $result->snapshot;
    assert($snapshot !== null);
    expect((float) $snapshot->price)->toBe(45.50)
        ->and($snapshot->currency)->toBe('EUR');
});

test('OutOfStock availability translates to in_stock=false', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Sold out',
        'offers' => [
            '@type' => 'Shop',
            'price' => '10.00',
            'priceCurrency' => 'EUR',
            'availability' => 'http://schema.org/OutOfStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->snapshot?->inStock)->toBeFalse();
});

test('skips when JSON-LD has no Product/ProductGroup so weaker adapters can try', function (): void {
    // Mirrors dierapotheker.nl: only FAQPage / LocalBusiness JSON-LD blobs,
    // no schema.org Product entity. Returning failed would stop the chain
    // and starve the microdata/OG/generic adapters that CAN extract here.
    $json = json_encode([
        '@type' => 'FAQPage',
        'mainEntity' => [],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->isSkip())->toBeTrue();
});

test('failed when JSON-LD contains a Product but no extractable offer', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Has product, no offers',
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jsonld_no_offer');
});

test('skips when malformed JSON (no usable Product entity)', function (): void {
    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd('{not json'));

    expect($result->isSkip())->toBeTrue();
});

test('multiple ld+json scripts: first valid Shop wins', function (): void {
    $junk = json_encode(['@type' => 'BreadcrumbList']);
    $product = json_encode([
        '@type' => 'Product',
        'name' => 'Late',
        'offers' => ['@type' => 'Shop', 'price' => '15.00', 'priceCurrency' => 'EUR'],
    ]);

    $html = '<html><head>'
        . '<script type="application/ld+json">' . $junk . '</script>'
        . '<script type="application/ld+json">' . $product . '</script>'
        . '</head><body></body></html>';

    $result = new JsonLdAdapter()->extract('https://x.test', $html);

    expect($result->snapshot?->price)->toBe('15.00');
});

test('normalizes European decimal separator', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Euro',
        'offers' => ['@type' => 'Shop', 'price' => '1.299,99', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->snapshot?->price)->toBe('1299.99');
});

test('image as schema.org ImageObject resolves to url', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'ImageObject',
        'image' => ['@type' => 'ImageObject', 'url' => 'https://shop.test/x.jpg'],
        'offers' => ['@type' => 'Shop', 'price' => '1.00', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://x.test', withJsonLd($json));

    expect($result->snapshot?->imageUrl)->toBe('https://shop.test/x.jpg');
});

test('matches the requested URL among ProductGroup variants', function (): void {
    $variantUrl = 'https://shop.test/p/three-pack/9200000087037725/';
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Classic Navulling',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'url' => 'https://shop.test/p/one-pack/9200000051357004/',
                'offers' => ['@type' => 'Shop', 'price' => '24.99', 'priceCurrency' => 'EUR', 'availability' => 'InStock'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'url' => $variantUrl,
                'offers' => ['@type' => 'Shop', 'price' => '52.86', 'priceCurrency' => 'EUR', 'availability' => 'InStock'],
            ],
        ],
        'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '24.99', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract($variantUrl, withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->title)->toBe('Feliway 3-pack')
        ->and($result->snapshot?->price)->toBe('52.86');
});

test('falls back to ProductGroup AggregateOffer lowPrice when no variant url matches', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Group',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'A',
                'url' => 'https://shop.test/p/a/',
                'offers' => ['@type' => 'Shop', 'price' => '15.00', 'priceCurrency' => 'EUR'],
            ],
        ],
        'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '15.00', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    // Different requested URL — no variant match → use AggregateOffer.
    $result = new JsonLdAdapter()->extract('https://shop.test/p/unknown/', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.00');
});

test('returns ambiguous when ProductGroup has multiple variants and no URL match', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Family',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'productID' => '111-1',
                'url' => 'https://shop.test/p/1pack/',
                'offers' => ['@type' => 'Offer', 'price' => '23.95', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'productID' => '111-3',
                'url' => 'https://shop.test/p/3pack/',
                'offers' => ['@type' => 'Offer', 'price' => '52.86', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://shop.test/canonical', withJsonLd($json));

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->variants)->toHaveCount(2)
        ->and($result->variants[0]->title)->toBe('Feliway 1-pack')
        ->and($result->variants[0]->key)->toBe('111-1')
        ->and($result->variants[1]->price)->toBe('52.86');
});

test('picks the variant matching context.variantKey by productID', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Feliway Family',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'Feliway 1-pack',
                'productID' => '111-1',
                'url' => 'https://shop.test/p/1pack/',
                'offers' => ['@type' => 'Offer', 'price' => '23.95', 'priceCurrency' => 'EUR'],
            ],
            [
                '@type' => 'Product',
                'name' => 'Feliway 3-pack',
                'productID' => '111-3',
                'url' => 'https://shop.test/p/3pack/',
                'offers' => ['@type' => 'Offer', 'price' => '52.86', 'priceCurrency' => 'EUR'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $context = new AdapterContext(variantKey: '111-3');
    $result = new JsonLdAdapter()->extract('https://shop.test/canonical', withJsonLd($json), $context);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('52.86')
        ->and($result->snapshot?->title)->toBe('Feliway 3-pack');
});

test('accepts the non-spec capitalized Price key dirk.nl emits', function (): void {
    // Trimmed replica of dirk.nl product JSON-LD (observed 2026-08-31).
    $json = json_encode([
        '@context' => 'http://schema.org/',
        '@type' => 'Product',
        'name' => 'Beemster Kaas extra belegen 48+ plakken',
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'EUR',
            'Price' => 1.69,
            'url' => 'https://www.dirk.nl/boodschappen/x/x/x/115212',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://www.dirk.nl/boodschappen/x/x/x/115212', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->currency)->toBe('EUR');
});
