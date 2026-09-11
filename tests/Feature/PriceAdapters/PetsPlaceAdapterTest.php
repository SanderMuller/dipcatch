<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\PetsPlaceAdapter;

beforeEach(function (): void {
    $this->adapter = new PetsPlaceAdapter();
});

test('skips when the URL host is not petsplace.nl', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('matches www.petsplace.nl via normalized host', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Trixie Mini Mover',
        'offers' => ['@type' => 'Shop', 'price' => '19.99', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://www.petsplace.nl/trixie-mini-mover', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99');
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Trixie Mini Mover',
        'offers' => [
            '@type' => 'Shop',
            'price' => '19.99',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://petsplace.nl/trixie-dog-activity-mini-mover-4011905320298-pps',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to the Magento final price when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>Trixie Mini Mover</h1>
  <meta property="og:image" content="https://cdn.petsplace.nl/img.jpg" />
  <span data-price-amount="24.99" data-price-type="oldPrice"></span>
  <span data-price-amount="19.99" data-price-type="finalPrice" class="price-wrapper"></span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://petsplace.nl/trixie-mini-mover', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Trixie Mini Mover')
        ->and($result->snapshot?->imageUrl)->toBe('https://cdn.petsplace.nl/img.jpg');
});

test('fails when only a struck-through Magento list price is present', function (): void {
    $html = <<<'HTML'
<html><body>
  <span data-price-amount="24.99" data-price-type="oldPrice"></span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://petsplace.nl/trixie-mini-mover', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('petsplace_extraction_failed');
});

test('fails when a petsplace page has neither JSON-LD nor a price amount', function (): void {
    $result = $this->adapter->extract('https://petsplace.nl/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('petsplace_extraction_failed');
});
