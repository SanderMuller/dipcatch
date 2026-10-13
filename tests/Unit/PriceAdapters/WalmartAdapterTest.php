<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\WalmartAdapter;

beforeEach(function (): void {
    $this->adapter = new WalmartAdapter();
});

test('skips when the URL host is not walmart.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('reads the hero price, not a multipack tile or another itemprop', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'CeraVe Moisturizing Cream 16 oz',
    ], JSON_THROW_ON_ERROR);

    $html = <<<HTML
<html><head>
  <script type="application/ld+json">{$json}</script>
  <meta property="og:title" content="CeraVe Moisturizing Cream 16 oz">
  <meta property="og:image" content="https://www.walmart.com/cerave.jpg">
</head><body>
  <span hidden="" itemProp="priceCurrency">USD</span>
  <span itemProp="price">\$99.00</span>
  <span data-seo-id="hero-price">\$15.97</span>
  <span data-testid="variant-tile-price-text-2">\$31.94</span>
</body></html>
HTML;

    $result = $this->adapter->extract(
        'https://www.walmart.com/ip/CeraVe-Moisturizing-Cream-16-oz/681955595',
        $html,
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.97')
        ->and($result->snapshot?->currency)->toBe('USD')
        ->and($result->snapshot?->title)->toBe('CeraVe Moisturizing Cream 16 oz')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.walmart.com/cerave.jpg');
});

test('delegates to JsonLdAdapter when the page publishes a product offer', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'CeraVe Moisturizing Cream 16 oz',
        'offers' => [
            '@type' => 'Offer',
            'price' => '15.97',
            'priceCurrency' => 'USD',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.walmart.com/ip/cerave/681955595',
        str_replace('</body>', '<span data-seo-id="hero-price">$99.00</span></body>', withJsonLd($json)),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.97')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('fails when a Walmart page has neither JSON-LD nor a hero price', function (): void {
    $result = $this->adapter->extract('https://www.walmart.com/ip/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('walmart_extraction_failed');
});
