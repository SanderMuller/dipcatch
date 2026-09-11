<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\MedpetsAdapter;

beforeEach(function (): void {
    $this->adapter = new MedpetsAdapter();
});

test('skips when the URL host is not a Medpets shop', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('matches medpets.be as well as medpets.nl', function (): void {
    $html = '<h1>4lazylegs Hondendraagzak</h1><div data-product-price="38.6"></div>';

    $result = $this->adapter->extract('https://www.medpets.be/4lazylegs-hondendraagzak', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('38.6')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => '4lazylegs Hondendraagzak',
        'offers' => [
            '@type' => 'Shop',
            'price' => '38.60',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://www.medpets.nl/4lazylegs-hondendraagzak', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('38.60')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to data-product-price when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>4lazylegs Hondendraagzak</h1>
  <div data-product-price="38.6" data-free-shipping-price="69.00"></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.medpets.nl/4lazylegs-hondendraagzak', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('38.6')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('4lazylegs Hondendraagzak');
});

test('fails when a medpets page has neither JSON-LD nor a product price', function (): void {
    $result = $this->adapter->extract('https://www.medpets.nl/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('medpets_extraction_failed');
});
