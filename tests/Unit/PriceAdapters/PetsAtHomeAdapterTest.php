<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\Hosts\PetsAtHomeAdapter;

beforeEach(function (): void {
    $this->adapter = new PetsAtHomeAdapter();
});

test('skips when the URL host is not petsathome.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('reads the standard shelf price when the subscription offer is listed first', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Product',
                'name' => 'Royal Canin Mini Dry Adult Dog Food',
                'offers' => [
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27223',
                        'price' => 21.5,
                        'description' => 'Easy Repeat subscription price',
                    ],
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27223',
                        'price' => 23.89,
                        'description' => 'Standard price',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.petsathome.com/product/royal-canin-mini-dry-adult-dog-food/P687',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('23.89')
        ->and($result->snapshot?->currency)->toBe('GBP')
        ->and($result->snapshot?->title)->toBe('Royal Canin Mini Dry Adult Dog Food');
});

test('asks which pack when two standard prices remain', function (): void {
    $result = $this->adapter->extract(
        'https://www.petsathome.com/product/royal-canin-mini-dry-adult-dog-food/P687',
        withJsonLd(petsAtHomeTwoPackJson()),
    );

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->variants)->toHaveCount(2)
        ->and($result->snapshot)->toBeNull();
});

test('an answered pack resolves to that pack\'s standard price', function (): void {
    $answered = $this->adapter->extract(
        'https://www.petsathome.com/product/royal-canin-mini-dry-adult-dog-food/P687',
        withJsonLd(petsAtHomeTwoPackJson()),
        new AdapterContext(variantKey: '27224'),
    );

    expect($answered->isSuccess())->toBeTrue()
        ->and($answered->snapshot?->price)->toBe('43.19');
});

test('fails when every remaining offer is a subscription', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Product',
                'name' => 'Royal Canin Mini Dry Adult Dog Food',
                'offers' => [
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27223',
                        'price' => 21.5,
                        'description' => 'Easy Repeat subscription price',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.petsathome.com/product/royal-canin-mini-dry-adult-dog-food/P687',
        withJsonLd($json),
    );

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('petsathome_extraction_failed');
});

test('fails when a petsathome page has no JSON-LD', function (): void {
    $result = $this->adapter->extract('https://www.petsathome.com/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('petsathome_extraction_failed');
});

function petsAtHomeTwoPackJson(): string
{
    return json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Product',
                'name' => 'Royal Canin Mini Dry Adult Dog Food',
                'offers' => [
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27223',
                        'price' => 23.89,
                        'description' => 'Standard price',
                    ],
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27223',
                        'price' => 21.5,
                        'description' => 'Easy Repeat subscription price',
                    ],
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27224',
                        'price' => 43.19,
                        'description' => 'Standard price',
                    ],
                    [
                        '@type' => 'Offer',
                        'priceCurrency' => 'GBP',
                        'sku' => '27224',
                        'price' => 38.87,
                        'description' => 'Easy Repeat subscription price',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * A one-bag page as petsathome.com served it on 2026-10-06: the JSON-LD
 * names the product without its size, the page state names the bag.
 */
function petsAtHomeBagPage(float $statePrice = 50.19, int|string $sku = '7108890'): string
{
    $json = json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'AATU Chicken Adult Dry Dog Food', 'offers' => [
        ['@type' => 'Offer', 'priceCurrency' => 'GBP', 'sku' => '7108890', 'price' => 45.17, 'description' => 'Easy Repeat subscription price'],
        ['@type' => 'Offer', 'priceCurrency' => 'GBP', 'sku' => $sku, 'price' => 50.19, 'description' => 'Standard price'],
    ]], JSON_THROW_ON_ERROR);
    $state = json_encode(['props' => ['pageProps' => ['baseProduct' => ['products' => [
        ['id' => '7108890', 'label' => '5kg', 'netAmount' => 5, 'uomValue' => 'kg', 'price' => ['base' => $statePrice]],
    ]]]]], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $json . '</script></head><body>'
        . '<script id="__NEXT_DATA__" type="application/json">' . $state . '</script></body></html>';
}

test('reads the bag size the page state states for the priced offer', function (): void {
    $result = $this->adapter->extract('https://www.petsathome.com/product/aatu-80-20-dry-adult-dog-food-free-run-chicken/P3794', petsAtHomeBagPage());

    expect($result->snapshot?->price)->toBe('50.19')
        ->and($result->snapshot?->packSize)->toBe('5 kg');
});

test('matches a numeric sku to the page state', function (): void {
    $result = $this->adapter->extract('https://www.petsathome.com/product/aatu-80-20-dry-adult-dog-food-free-run-chicken/P3794', petsAtHomeBagPage(sku: 7108890));

    expect($result->snapshot?->packSize)->toBe('5 kg');
});

test('leaves the size unread when the page state prices that bag differently', function (): void {
    $result = $this->adapter->extract('https://www.petsathome.com/product/aatu-80-20-dry-adult-dog-food-free-run-chicken/P3794', petsAtHomeBagPage(statePrice: 19.99));

    expect($result->snapshot?->price)->toBe('50.19')
        ->and($result->snapshot?->packSize)->toBeNull();
});
