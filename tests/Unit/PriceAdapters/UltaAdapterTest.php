<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\UltaAdapter;

beforeEach(function (): void {
    $this->adapter = new UltaAdapter();
});

test('skips when the URL host is not ulta.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Moisturizing Cream Body and Face Moisturizer - 1.8 oz',
        'sku' => '2532670',
        'offers' => [
            '@type' => 'Offer',
            'price' => '6.99',
            'priceCurrency' => 'USD',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.ulta.com/p/moisturizing-cream-body-face-moisturizer-xlsImpprod3530069?sku=2532670',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.99')
        ->and($result->snapshot?->currency)->toBe('USD')
        ->and($result->snapshot?->title)->toBe('Moisturizing Cream Body and Face Moisturizer - 1.8 oz');
});

test('falls back to the PDP price when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><head>
  <meta property="og:title" content="CeraVe Moisturizing Cream 1.8 oz">
  <meta property="og:image" content="https://www.ulta.com/cerave.jpg">
</head><body>
  <div class="pal-c-Price">
    <div class="pal-c-Price__priceContainer"><span>$18.99</span></div>
  </div>
  <div class="pal-c-Price pal-c-Price--PDP">
    <div class="pal-c-Price__priceContainer"><span>$6.99</span></div>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.ulta.com/p/cerave?sku=2532670', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.99')
        ->and($result->snapshot?->currency)->toBe('USD')
        ->and($result->snapshot?->title)->toBe('CeraVe Moisturizing Cream 1.8 oz')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.ulta.com/cerave.jpg');
});

test('fails when an Ulta page has neither JSON-LD nor a PDP price', function (): void {
    $result = $this->adapter->extract('https://www.ulta.com/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('ulta_extraction_failed');
});
