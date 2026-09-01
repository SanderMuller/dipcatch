<?php declare(strict_types=1);

use App\PriceAdapters\MicrodataAdapter;

beforeEach(function (): void {
    $this->adapter = new MicrodataAdapter();
});

test('skips when no itemprop=price is present', function (): void {
    $result = $this->adapter->extract('https://x.test', '<html><body>nothing here</body></html>');

    expect($result->isSkip())->toBeTrue();
});

test('extracts price + currency from microdata', function (): void {
    $html = <<<'HTML'
<div itemscope itemtype="http://schema.org/Product">
  <h1 itemprop="name">Widget</h1>
  <img itemprop="image" src="https://shop.test/w.jpg" />
  <div itemprop="offers" itemscope itemtype="http://schema.org/Shop">
    <meta itemprop="price" content="45.99" />
    <meta itemprop="priceCurrency" content="EUR" />
    <link itemprop="availability" href="http://schema.org/InStock" />
  </div>
</div>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('45.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Widget')
        ->and($result->snapshot?->inStock)->toBeTrue();
});

test('falls back to text when no content attribute', function (): void {
    $html = <<<'HTML'
<span itemprop="price">€29.99</span>
<meta itemprop="priceCurrency" content="EUR" />
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->price)->toBe('29.99');
});

test('failed when price marker exists but currency does not', function (): void {
    $html = '<span itemprop="price" content="99.00">99</span>';

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('microdata_no_currency');
});

test('OutOfStock availability turns in_stock off', function (): void {
    $html = <<<'HTML'
<meta itemprop="price" content="10.00" />
<meta itemprop="priceCurrency" content="EUR" />
<link itemprop="availability" href="http://schema.org/OutOfStock" />
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->inStock)->toBeFalse();
});

test('every field comes from the scope around the price, not a neighbouring product', function (): void {
    $html = <<<'HTML'
<div itemscope itemtype="https://schema.org/Product">
  <h1 itemprop="name">Other product</h1>
  <img itemprop="image" src="https://shop.test/other.jpg" />
  <meta itemprop="gtin13" content="0012345678905" />
</div>
<div itemscope itemtype="https://schema.org/Product">
  <h1 itemprop="name">Tracked product</h1>
  <img itemprop="image" src="https://shop.test/tracked.jpg" />
  <meta itemprop="gtin13" content="8712243044506" />
  <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
    <meta itemprop="price" content="12.50" />
    <meta itemprop="priceCurrency" content="EUR" />
    <link itemprop="availability" href="https://schema.org/InStock" />
  </div>
</div>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->title)->toBe('Tracked product')
        ->and($result->snapshot?->imageUrl)->toBe('https://shop.test/tracked.jpg')
        ->and($result->snapshot?->gtin)->toBe('8712243044506')
        ->and($result->snapshot?->price)->toBe('12.50');
});
