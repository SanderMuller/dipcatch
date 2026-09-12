<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\EtosAdapter;

beforeEach(function (): void {
    $this->adapter = new EtosAdapter();
});

test('skips when the URL host is not etos.nl', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Product',
                'name' => 'CeraVe Hydraterende Crème 454 GR',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '23.15',
                    'priceCurrency' => 'EUR',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.etos.nl/producten/cerave-hydraterende-creme-454-gr-120599610.html',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('23.15')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('CeraVe Hydraterende Crème 454 GR');
});

test('falls back to the sales price when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><head>
  <meta property="og:title" content="CeraVe Hydraterende Crème 340 GR">
  <meta property="og:image" content="https://www.etos.nl/cerave.jpg">
</head><body>
  <div class="c-price">
    <span class="price__item price__item--strike-through"><span class="price__value" content="22.45">22.45</span></span>
    <span class="price__item price__item--sales">
      <span class="price__value" property="price" content="18.85">
        <span class="price__base">18</span><span class="price__dot">.</span><sup class="price__decimals">85</sup>
      </span>
    </span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.etos.nl/producten/cerave-340', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('18.85')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('CeraVe Hydraterende Crème 340 GR')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.etos.nl/cerave.jpg');
});

test('fails when an etos page has neither JSON-LD nor a sales price', function (): void {
    $result = $this->adapter->extract('https://www.etos.nl/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('etos_extraction_failed');
});
