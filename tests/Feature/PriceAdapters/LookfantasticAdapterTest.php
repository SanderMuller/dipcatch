<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\Hosts\LookfantasticAdapter;

beforeEach(function (): void {
    $this->adapter = new LookfantasticAdapter();
});

test('skips when the URL host is not Lookfantastic or Cult Beauty', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('prices the pack whose SKU is in the Lookfantastic URL, not a cheaper sibling', function (): void {
    $html = lookfantasticProductGroupHtml();

    $result = $this->adapter->extract(
        'https://www.lookfantastic.com/p/cerave-moisturising-cream-pot/11798692/',
        $html,
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.5')
        ->and($result->snapshot?->currency)->toBe('GBP')
        ->and($result->snapshot?->title)->toBe('CeraVe Moisturising Cream Pot 340g');
});

test('pins the URL SKU when the probe already supplied a fallback currency', function (): void {
    $result = $this->adapter->extract(
        'https://www.lookfantastic.com/p/cerave-moisturising-cream-pot/11798692/',
        lookfantasticProductGroupHtml(),
        new AdapterContext(fallbackCurrency: 'EUR'),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.5')
        ->and($result->isAmbiguous())->toBeFalse();
});

test('an unanswered variant question is put to the user, not answered by the CSS fallback', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'CeraVe Moisturising Cream Pot',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'CeraVe Moisturising Cream Pot 177ml',
                'sku' => '11798693',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => 12,
                    'priceCurrency' => 'GBP',
                ],
            ],
            [
                '@type' => 'Product',
                'name' => 'CeraVe Moisturising Cream Pot 340g',
                'sku' => '11798692',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => 15.5,
                    'priceCurrency' => 'GBP',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $html = '<html><body><script type="application/ld+json">' . $json . '</script>'
        . '<div id="product-price"><span>£12.00</span></div></body></html>';

    $result = $this->adapter->extract('https://www.lookfantastic.com/p/cerave-moisturising-cream-pot', $html);

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->variants)->toHaveCount(2)
        ->and($result->snapshot)->toBeNull();

    $answered = $this->adapter->extract(
        'https://www.lookfantastic.com/p/cerave-moisturising-cream-pot',
        $html,
        new AdapterContext(variantKey: '11798692'),
    );

    expect($answered->isSuccess())->toBeTrue()
        ->and($answered->snapshot?->price)->toBe('15.5');
});

test('reads a Cult Beauty product JSON-LD offer', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'CeraVe Moisturising Cream Pot 454g',
        'sku' => '11798691',
        'offers' => [
            '@type' => 'Offer',
            'price' => 20.13,
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract(
        'https://www.cultbeauty.com/p/cerave-moisturising-cream-pot/11798691/',
        withJsonLd($json),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('20.13')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('CeraVe Moisturising Cream Pot 454g');
});

test('falls back to the painted product-price when JSON-LD is missing', function (): void {
    $html = <<<'HTML'
<html><head>
  <meta property="og:title" content="CeraVe Moisturising Cream Pot 340g">
  <meta property="og:image" content="https://www.lookfantastic.com/cerave.png">
</head><body>
  <div id="product-price">
    <p class="text-2xl font-medium"><span>£15.50</span></p>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.lookfantastic.com/p/cerave/11798692/', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('15.50')
        ->and($result->snapshot?->currency)->toBe('GBP')
        ->and($result->snapshot?->title)->toBe('CeraVe Moisturising Cream Pot 340g')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.lookfantastic.com/cerave.png');
});

test('reads a euro amount from Cult Beauty CSS when the page geo-prices', function (): void {
    $html = <<<'HTML'
<html><head>
  <meta property="og:title" content="CeraVe Moisturising Cream Pot 454g">
</head><body>
  <div id="product-price"><p><span>20.13€</span></p></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.cultbeauty.com/p/cerave/11798691/', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('20.13')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('a Cult Beauty CSS amount with no currency symbol uses the probe fallback', function (): void {
    $html = <<<'HTML'
<html><body>
  <div id="product-price"><p><span>20.13</span></p></div>
</body></html>
HTML;

    $result = $this->adapter->extract(
        'https://www.cultbeauty.com/p/cerave/11798691/',
        $html,
        new AdapterContext(fallbackCurrency: 'EUR'),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('20.13')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('fails when a Lookfantastic page has neither JSON-LD nor a product price', function (): void {
    $result = $this->adapter->extract('https://www.lookfantastic.com/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('lookfantastic_extraction_failed');
});

function lookfantasticProductGroupHtml(): string
{
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'CeraVe Moisturising Cream Pot',
        'hasVariant' => [
            [
                '@type' => 'Product',
                'name' => 'CeraVe Moisturising Cream Pot 177ml',
                'sku' => '11798693',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => 12,
                    'priceCurrency' => 'GBP',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
            [
                '@type' => 'Product',
                'name' => 'CeraVe Moisturising Cream Pot 454g',
                'sku' => '11798691',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => 17.5,
                    'priceCurrency' => 'GBP',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
            [
                '@type' => 'Product',
                'name' => 'CeraVe Moisturising Cream Pot 340g',
                'sku' => '11798692',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => 15.5,
                    'priceCurrency' => 'GBP',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return str_replace('</body>', '<div id="product-price"><span>£12.00</span></div></body>', withJsonLd($json));
}
