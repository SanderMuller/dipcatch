<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\GenericAdapter;
use App\PriceAdapters\MicrodataAdapter;
use App\PriceAdapters\OpenGraphAdapter;
use App\PriceAdapters\ShopAdapter;

test('a reader that cannot tell variants apart refuses a chosen variant', function (ShopAdapter $adapter): void {
    $html = '<html><head><meta property="og:price:amount" content="36.99"><meta property="og:price:currency" content="EUR"></head>'
        . '<body><div itemscope itemtype="https://schema.org/Offer"><span itemprop="price" content="36.99">€ 36,99</span>'
        . '<meta itemprop="priceCurrency" content="EUR"></div><span class="price">€ 36,99</span></body></html>';

    $chosen = $adapter->extract('https://shop.test/p/1', $html, new AdapterContext(variantKey: '17712244'));
    $unchosen = $adapter->extract('https://shop.test/p/1', $html);

    expect($chosen->isFailed())->toBeTrue()
        ->and($chosen->failureReason)->toBe('variant_not_readable')
        ->and($unchosen->snapshot?->price)->toBe('36.99');
})->with([
    'microdata' => [new MicrodataAdapter()],
    'opengraph' => [new OpenGraphAdapter()],
    'generic' => [new GenericAdapter()],
]);
