<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\BolAdapter;

beforeEach(function (): void {
    $this->adapter = new BolAdapter();
});

test('skips when the URL host is not bol.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('skips for non-bol www. host variants', function (): void {
    // BolAdapter uses normalized host, which strips www. → bol.com still matches.
    // This test confirms a totally different host skips.
    $result = $this->adapter->extract('https://www.coolblue.nl/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('matches www.bol.com via normalized host', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Test',
        'offers' => ['@type' => 'Shop', 'price' => '50.00', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://www.bol.com/p/1', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('50.00');
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Sony WH-1000XM5',
        'offers' => [
            '@type' => 'Shop',
            'price' => '289.99',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://bol.com/headphones/9200000123', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('289.99')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to CSS extraction when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1 class="product-title">Bol Product</h1>
  <meta property="og:image" content="https://bol.com/img.jpg" />
  <span data-test="price">€ 19,99</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://bol.com/p/1', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Bol Product')
        ->and($result->snapshot?->imageUrl)->toBe('https://bol.com/img.jpg');
});

test('failed when bol page has neither JSON-LD nor known price markers', function (): void {
    $html = '<html><body><p>broken page</p></body></html>';

    $result = $this->adapter->extract('https://bol.com/p/1', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('bol_extraction_failed');
});
