<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\OrdinaryAdapter;

beforeEach(function (): void {
    $this->adapter = new OrdinaryAdapter();
});

test('skips when the URL host is not theordinary.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@context' => 'http://schema.org/',
        '@type' => 'Product',
        'name' => 'Niacinamide 10% + Zinc 1%',
        'offers' => [
            '@type' => 'Offer',
            'price' => '6.00',
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://theordinary.com/en-nl/niacinamide-10-zinc-1-serum-100436.html',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.00')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Niacinamide 10% + Zinc 1%');
});

test('falls back to the sales value when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><head>
  <meta property="og:title" content="Niacinamide 10% + Zinc 1%">
  <meta property="og:image" content="https://theordinary.com/niacinamide.png">
</head><body>
  <div class="product-price">
    <span class="strike-through"><span class="value" content="7.20">€7.20</span></span>
    <span class="sales">
      <span class="value" content="6.00">€6.00 EUR</span>
    </span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://theordinary.com/en-nl/niacinamide.html', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.00')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Niacinamide 10% + Zinc 1%')
        ->and($result->snapshot?->imageUrl)->toBe('https://theordinary.com/niacinamide.png');
});

test('reads GBP from the sales value on a UK Ordinary page', function (): void {
    $html = <<<'HTML'
<html><body>
  <div class="product-price">
    <span class="sales"><span class="value" content="6.00">£6.00</span></span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://theordinary.com/en-gb/niacinamide.html', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.00')
        ->and($result->snapshot?->currency)->toBe('GBP');
});

test('reads USD from the sales value on a US Ordinary page', function (): void {
    $html = <<<'HTML'
<html><body>
  <div class="product-price">
    <span class="sales"><span class="value" content="6.00">$6.00</span></span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://theordinary.com/en-us/niacinamide.html', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('6.00')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('fails when an ordinary page has neither JSON-LD nor a sales price', function (): void {
    $result = $this->adapter->extract('https://theordinary.com/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('ordinary_extraction_failed');
});
