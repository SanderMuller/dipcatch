<?php declare(strict_types=1);

use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\Hosts\LuxplusAdapter;

/**
 * A luxplus.nl product page cut down from 2026-10-17: the JSON-LD offer at
 * the member's price, a recommendation tile that reuses the product-type
 * class, the h1 with its own product-type block, and the price chooser.
 */
function luxplusPage(string $normalPrice = '<span class="currency">€</span><span>5</span><sup class="decimal-amount">39</sup>', string $availability = 'http://schema.org/InStock', string $canonical = '<meta property="og:url" content="https://www.luxplus.nl/product/sanex-expert-skin-health-sensitive-shower-gel-600-ml">'): string
{
    return <<<HTML
        <html><head>
        {$canonical}
        <meta property="og:title" content="Sanex Expert Skin Health Sensitive Shower Gel 600 ml">
        <meta property="og:image" content="https://luxplus.imgix.net/content/product/image/127935.png">
        <script type="application/ld+json">{"@context":"http://schema.org/","@type":"Product","name":"Sanex Expert Skin Health Sensitive Shower Gel","offers":{"@type":"Offer","priceCurrency":"EUR","price":"3.95","availability":"{$availability}"}}</script>
        </head><body>
        <div class="lux-field lux-product-type"><p>Bodylotion</p><p>250 ml</p></div>
        <div class="lux-right">
            <h1 class="lux-field lux-product-title"><a class="lux-field lux-product-brand" href="/merk/sanex">Sanex</a> <span class="lux-field lux-product-name">Expert Skin Health Sensitive Shower Gel</span></h1>
            <div class="lux-field lux-product-type"><p>Douchegel</p><p>600 ml</p></div>
        </div>
        <ul class="box-inner price-chooser be-nl not-active">
            <li class="lux-radio discount">
                <div class="price-wrapper discount"><p class="price-text">Actieprijs</p><p class="price-amount"><span class="currency">€</span><span>3</span><sup class="decimal-amount">55</sup></p></div>
                <div class="price-wrapper member"><p class="price-text">Memberprijs</p><p class="price-amount"><span class="currency">€</span><span>3</span><sup class="decimal-amount">95</sup></p></div>
            </li>
            <li class="lux-radio">
                <div class="price-wrapper normal"><p class="price-text">Normale prijs</p><p class="price-amount">{$normalPrice}</p></div>
            </li>
        </ul>
        </body></html>
        HTML;
}

beforeEach(function (): void {
    $this->adapter = new LuxplusAdapter();
    $this->url = 'https://www.luxplus.nl/product/sanex-expert-skin-health-sensitive-shower-gel-600-ml';
});

test('skips a host that is not luxplus.nl', function (): void {
    expect($this->adapter->extract('https://other.nl/product/sanex-expert-skin-health-sensitive-shower-gel-600-ml', luxplusPage())->isSkip())->toBeTrue();
});

test('reads the normal price, not the member or action price the JSON-LD and chooser lead with', function (): void {
    $result = $this->adapter->extract($this->url . '?gsmp=1', luxplusPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('5.39')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Sanex Expert Skin Health Sensitive Shower Gel 600 ml')
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://luxplus.imgix.net/content/product/image/127935.png');
});

test('reads the pack size beside the h1, not a recommendation tile', function (): void {
    expect($this->adapter->extract($this->url, luxplusPage())->snapshot?->packSize)->toBe('600 ml');
});

test('reports stock from the JSON-LD offer', function (): void {
    expect($this->adapter->extract($this->url, luxplusPage(availability: 'http://schema.org/OutOfStock'))->snapshot?->inStock)->toBeFalse();
});

test('a page without the normal price fails instead of reading the member price', function (string $normalPrice): void {
    $result = $this->adapter->extract($this->url, luxplusPage(normalPrice: $normalPrice));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('luxplus_no_price');
})->with([
    'no amount' => [''],
    'no cents' => ['<span class="currency">€</span><span>5</span>'],
]);

test('a page describing a different product than the URL, or naming none, is refused', function (string $url, string $canonical): void {
    $result = $this->adapter->extract($url, luxplusPage(canonical: $canonical));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('luxplus_product_mismatch');
})->with([
    'another product' => ['https://www.luxplus.nl/product/sanex-shower-gel-zero-600-ml', '<meta property="og:url" content="https://www.luxplus.nl/product/sanex-expert-skin-health-sensitive-shower-gel-600-ml">'],
    'no canonical URL' => ['https://www.luxplus.nl/product/sanex-expert-skin-health-sensitive-shower-gel-600-ml', ''],
]);

test('the production adapter chain reads luxplus.nl with this adapter, not the JSON-LD member price', function (): void {
    app()->forgetInstance(AdapterResolver::class);

    $result = app(AdapterResolver::class)->resolve($this->url, luxplusPage());

    expect($result->adapterKey)->toBe('luxplus')
        ->and($result->snapshot?->price)->toBe('5.39');
});
