<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\JsonLdAdapter;

/**
 * @param  list<array<string, mixed>>  $entities
 */
function ldPage(array $entities, string $extraHead = ''): string
{
    $scripts = implode('', array_map(
        static fn (array $entity): string => '<script type="application/ld+json">' . json_encode($entity, JSON_THROW_ON_ERROR) . '</script>',
        $entities,
    ));

    return "<html><head>{$scripts}{$extraHead}</head><body></body></html>";
}

/**
 * @return array<string, mixed>
 */
function ldProduct(string $name, string $price, ?string $url = null, string $type = 'Product'): array
{
    return array_filter([
        '@context' => 'https://schema.org',
        '@type' => $type,
        'name' => $name,
        'url' => $url,
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], static fn (mixed $value): bool => $value !== null);
}

test('a standalone Offer prices a page that has no Product', function (): void {
    $html = ldPage([['@context' => 'https://schema.org', '@type' => 'Offer', 'name' => 'Lamp', 'price' => '19.95', 'priceCurrency' => 'EUR']]);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.95');
});

test('an unrelated top-level Offer never displaces the Product\'s own offer', function (): void {
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'Offer', 'name' => 'Free shipping', 'price' => '0.00', 'priceCurrency' => 'EUR'],
        ldProduct('Drill', '89.00'),
    ]);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', $html);

    expect($result->snapshot?->price)->toBe('89.00')
        ->and($result->snapshot?->title)->toBe('Drill');
});

test('a Product stated only inside a BuyAction is read', function (string $shape): void {
    $action = ['@context' => 'https://schema.org', '@type' => 'BuyAction', 'object' => ldProduct('AirPods Pro', '262.23', 'https://www.mediamarkt.nl/nl/product/_airpods-1.html')];
    $decoded = match ($shape) {
        'top level' => $action,
        'list' => [$action],
        'graph' => ['@context' => 'https://schema.org', '@graph' => [$action]],
        default => throw new InvalidArgumentException($shape),
    };

    $html = '<html><head><script type="application/ld+json">' . json_encode($decoded, JSON_THROW_ON_ERROR) . '</script></head></html>';

    $result = new JsonLdAdapter()->extract('https://www.mediamarkt.nl/nl/product/_airpods-1.html', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('262.23')
        ->and($result->snapshot?->title)->toBe('AirPods Pro');
})->with(['top level', 'list', 'graph']);

test('a Product repeated inside a BuyAction reads as before and is no tie', function (): void {
    $url = 'https://shop.test/p/drill-18v';
    $product = ldProduct('Drill 18V', '89.00', $url);

    $result = new JsonLdAdapter()->extract($url, ldPage([$product, ['@type' => 'BuyAction', 'object' => $product]]));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('89.00');
});

test('an unrelated recommendation Product does not hide the Action\'s Product', function (): void {
    $url = 'https://shop.test/p/drill-18v';

    $result = new JsonLdAdapter()->extract($url, ldPage([
        ldProduct('Screwdriver set', '12.00', 'https://shop.test/p/screwdrivers'),
        ['@type' => 'BuyAction', 'object' => ldProduct('Drill 18V', '89.00', $url)],
    ]));

    expect($result->snapshot?->price)->toBe('89.00')
        ->and($result->snapshot?->title)->toBe('Drill 18V');
});

test('a canonical Product naming only the page does not stop a more precise Action variant', function (): void {
    $variantUrl = 'https://shop.test/p/drill?sku=B';
    $canonical = ldProduct('Drill', '79.00', 'https://shop.test/p/drill');
    $canonical['sku'] = 'A';
    $variant = ldProduct('Drill, sku B', '99.00', $variantUrl);
    $variant['sku'] = 'B';

    $result = new JsonLdAdapter()->extract($variantUrl, ldPage([$canonical, ['@type' => 'BuyAction', 'object' => $variant]]));

    expect($result->snapshot?->price)->toBe('99.00');
});

test('a full-URL @type is read like the short form, list entries too', function (mixed $type): void {
    $result = new JsonLdAdapter()->extract('https://www.prenatal.nl/fopspeen-1/', ldPage([array_merge(ldProduct('Fopspeen', '10.99'), ['@type' => $type])]));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('10.99')
        ->and($result->snapshot?->inStock)->toBeTrue();
})->with([
    'https' => ['https://schema.org/Product'],
    'http' => ['http://schema.org/Product'],
    'mixed list' => [['https://schema.org/Product', 'Thing']],
]);

test('a full-URL-only Product without an offer still reaches OpenGraph', function (): void {
    $html = ldPage(
        [['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Fopspeen']],
        '<meta property="og:title" content="Fopspeen"><meta property="product:price:amount" content="10.99"><meta property="product:price:currency" content="EUR">',
    );

    expect(new JsonLdAdapter()->extract('https://www.prenatal.nl/fopspeen-1/', $html)->isSkip())->toBeTrue();

    $resolved = app(AdapterResolver::class)->resolve('https://www.prenatal.nl/fopspeen-1/', $html);

    expect($resolved->isSuccess())->toBeTrue()
        ->and($resolved->snapshot?->price)->toBe('10.99');
});

test('a short-form Product without an offer still fails as before', function (): void {
    $html = ldPage([['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'No offer']]);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jsonld_no_offer');
});

test('a strikethrough or list price spec is never the selling price', function (string $priceType): void {
    $offer = ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => $priceType, 'price' => '100.00', 'priceCurrency' => 'EUR'],
        ['@type' => 'UnitPriceSpecification', 'price' => '80.00', 'priceCurrency' => 'EUR'],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Kettle', 'offers' => $offer]]));

    expect($result->snapshot?->price)->toBe('80.00');
})->with(['https://schema.org/StrikethroughPrice', 'StrikethroughPrice', 'https://schema.org/ListPrice']);

test('an offer with only a strikethrough spec has no selling price', function (): void {
    $offer = ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '100.00'],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Kettle', 'offers' => $offer]]));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jsonld_no_price');
});

test('the currency comes from the spec that set the price', function (): void {
    $offer = ['@type' => 'Offer', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'StrikethroughPrice', 'price' => '100.00', 'priceCurrency' => 'USD'],
        ['@type' => 'UnitPriceSpecification', 'price' => '80.00', 'priceCurrency' => 'EUR'],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Kettle', 'offers' => $offer]]));

    expect($result->snapshot?->price)->toBe('80.00')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('a direct price whose only currency sits on a strikethrough spec still reads', function (): void {
    $offer = ['@type' => 'Offer', 'price' => '80.00', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'StrikethroughPrice', 'price' => '100.00', 'priceCurrency' => 'EUR'],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Kettle', 'offers' => $offer]]));

    expect($result->snapshot?->price)->toBe('80.00')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('a pinned variant key still reaches an Action variant', function (): void {
    $url = 'https://shop.test/p/drill';
    $variant = ldProduct('Drill B', '99.00', $url);
    $variant['sku'] = 'B';

    $result = new JsonLdAdapter()->extract($url, ldPage([['@type' => 'BuyAction', 'object' => $variant]]), new AdapterContext(variantKey: 'B'));

    expect($result->snapshot?->price)->toBe('99.00');
});

test('a full-URL Product whose offer has no price or currency still reaches OpenGraph', function (array $offer): void {
    $html = ldPage(
        [['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Fopspeen', 'offers' => ['@type' => 'Offer'] + $offer]],
        '<meta property="og:title" content="Fopspeen"><meta property="og:price:amount" content="10.99"><meta property="og:price:currency" content="EUR">',
    );

    $resolved = app(AdapterResolver::class)->resolve('https://www.prenatal.example/fopspeen-1/', $html);

    expect($resolved->snapshot?->price)->toBe('10.99');
})->with([
    'no price' => [['priceCurrency' => 'EUR']],
    'no currency' => [['price' => '10.99']],
]);

test('an offer-less full-URL Product does not hide a later readable Product', function (): void {
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Breadcrumb product'],
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Drill', 'offers' => ['@type' => 'Offer', 'price' => '89.00', 'priceCurrency' => 'EUR']],
    ]);

    expect(new JsonLdAdapter()->extract('https://shop.test/p/1', $html)->snapshot?->price)->toBe('89.00');
});

test('a standalone AggregateOffer wins over an earlier shipping Offer', function (): void {
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'Offer', 'name' => 'Shipping', 'price' => '0.00', 'priceCurrency' => 'EUR'],
        ['@context' => 'https://schema.org', '@type' => 'AggregateOffer', 'name' => 'Drill', 'lowPrice' => '89.00', 'priceCurrency' => 'EUR'],
    ]);

    expect(new JsonLdAdapter()->extract('https://shop.test/p/1', $html)->snapshot?->price)->toBe('89.00');
});

test('a full-URL Product with an unreadable offer does not hide a later readable Product', function (): void {
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Breadcrumb product', 'offers' => ['@type' => 'Offer', 'priceCurrency' => 'EUR']],
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Drill', 'offers' => ['@type' => 'Offer', 'price' => '89.00', 'priceCurrency' => 'EUR']],
    ]);

    expect(new JsonLdAdapter()->extract('https://shop.test/p/1', $html)->snapshot?->price)->toBe('89.00');
});

test('an unreadable full-URL Product naming the page does not hide a readable Product at the same URL', function (): void {
    $url = 'https://shop.test/p/drill';
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Drill', 'url' => $url, 'offers' => ['@type' => 'Offer', 'priceCurrency' => 'EUR']],
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Drill', 'url' => $url, 'offers' => ['@type' => 'Offer', 'price' => '89.00', 'priceCurrency' => 'EUR']],
    ]);

    expect(new JsonLdAdapter()->extract($url, $html)->snapshot?->price)->toBe('89.00');
});

test('a member price stated as the offer price is not read as the price', function (): void {
    $offer = ['@type' => 'Offer', 'price' => '42.49', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '49.99'],
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/SalePrice', 'price' => '42.49'],
        ['@type' => 'UnitPriceSpecification', 'price' => '42.49', 'validForMemberTier' => ['@type' => 'MemberProgramTier', 'name' => 'autoshipment']],
    ]];
    $html = ldPage([['@type' => 'Product', 'name' => 'Dog food', 'offers' => $offer]], '<meta property="product:price:amount" content="42.49">');

    $result = app(AdapterResolver::class)->resolve('https://shop.test/p/1', $html);

    // Failed, so no weaker reader prices the page with the member price.
    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('jsonld_member_price');
});

test('a member tier that only earns points on the regular price leaves the price readable', function (): void {
    $offer = ['@type' => 'Offer', 'price' => '49.99', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'price' => '49.99', 'validForMemberTier' => ['@type' => 'MemberProgramTier', 'name' => 'Standard'], 'membershipPointsEarned' => 50],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Dog food', 'offers' => $offer]]));

    expect($result->snapshot?->price)->toBe('49.99');
});

test('a member price spec is never the selling price', function (): void {
    $offer = ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'price' => '40.00', 'validForMemberTier' => ['@type' => 'MemberProgramTier', 'name' => 'Gold']],
        ['@type' => 'UnitPriceSpecification', 'price' => '50.00'],
    ]];

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', ldPage([['@type' => 'Product', 'name' => 'Dog food', 'offers' => $offer]]));

    expect($result->snapshot?->price)->toBe('50.00');
});

test('a Product whose @id is the page address is the one the page shows', function (): void {
    // petsmart.com lists one Product per bag size, each with the url of its
    // own size page, and gives the size on show the page address as @id.
    $page = 'https://www.petsmart.com/dog/food/dry-food/pro-plan-salmon-formula-57927.html';
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Pro Plan 24 lb', 'sku' => '5299150',
            'url' => 'https://www.petsmart.com/dog/food/dry-food/pro-plan-salmon-formula-5299150.html',
            'offers' => ['@type' => 'Offer', 'price' => '77.99', 'priceCurrency' => 'USD']],
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Pro Plan 34 lb', 'sku' => '5345204', '@id' => $page,
            'url' => 'https://www.petsmart.com/dog/food/dry-food/pro-plan-salmon-formula-5345204.html',
            'offers' => ['@type' => 'Offer', 'price' => '97.99', 'priceCurrency' => 'USD']],
    ]);

    $result = new JsonLdAdapter()->extract($page . '?redirected=true', $html);

    expect($result->snapshot?->price)->toBe('97.99');
});

test('a fragment @id does not name the page', function (): void {
    $page = 'https://shop.test/p/large';
    $html = ldPage([
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Small', '@id' => $page . '#small', 'url' => 'https://shop.test/p/small',
            'offers' => ['@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'EUR']],
        ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Large', '@id' => $page . '#large', 'url' => $page,
            'offers' => ['@type' => 'Offer', 'price' => '30.00', 'priceCurrency' => 'EUR']],
    ]);

    expect(new JsonLdAdapter()->extract($page, $html)->snapshot?->price)->toBe('30.00');
});

test('a member price is refused for a full-URL Product too, so OpenGraph cannot read it', function (): void {
    $offer = ['@type' => 'Offer', 'price' => '42.49', 'priceCurrency' => 'EUR', 'priceSpecification' => [
        ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '49.99'],
        ['@type' => 'UnitPriceSpecification', 'price' => '42.49', 'validForMemberTier' => ['@type' => 'MemberProgramTier', 'name' => 'autoshipment']],
    ]];
    $html = ldPage(
        [['@context' => 'https://schema.org', '@type' => 'https://schema.org/Product', 'name' => 'Dog food', 'offers' => $offer]],
        '<meta property="og:price:amount" content="42.49"><meta property="og:price:currency" content="EUR">',
    );

    expect(app(AdapterResolver::class)->resolve('https://shop.test/p/1', $html)->isSuccess())->toBeFalse();
});

test('a fragment @id names the page for a Product with no url', function (): void {
    // Yoast and WooCommerce: `@id: <page>#product`, no `url`.
    $html = ldPage([['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Product', 'name' => 'Other', '@id' => 'https://shop.test/p/other#product', 'offers' => ['@type' => 'Offer', 'price' => '20.00', 'priceCurrency' => 'EUR']],
        ['@type' => 'Product', 'name' => 'Main', '@id' => 'https://shop.test/p/main#product', 'offers' => ['@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'EUR']],
    ]]]);

    expect(new JsonLdAdapter()->extract('https://shop.test/p/main', $html)->snapshot?->price)->toBe('10.00');
});
