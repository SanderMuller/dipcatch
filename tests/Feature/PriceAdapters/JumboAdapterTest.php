<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\JumboAdapter;

/**
 * Trimmed replica of jumbo.com's server-rendered price component
 * (observed 2026-08-31 on /producten/... pages).
 */
function jumboPriceComponent(string $screenreader, string $whole, string $fractional): string
{
    return <<<HTML
<div class="jum-price prominent product-price" data-testid="product-price">
  <div class="current-price">
    <div class="screenreader-only"><!--[-->{$screenreader}<!--]--></div>
    <span class="whole" aria-hidden="true">{$whole}</span><span class="fractional" aria-hidden="true">{$fractional}</span>
  </div>
</div>
HTML;
}

beforeEach(function (): void {
    $this->adapter = new JumboAdapter();
});

test('skips when the URL host is not jumbo.com', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('delegates to JsonLdAdapter on the happy path', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'HiPRO Protein Drink Mango 300ml',
        'offers' => [
            '@type' => 'AggregateOffer',
            'highPrice' => 14.34,
            'lowPrice' => 14.34,
            'offerCount' => 99,
            'priceCurrency' => 'EUR',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://www.jumbo.com/producten/hipro-494984DSL', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to the price component screenreader text when JSON-LD is missing', function (): void {
    $html = '<html><head>'
        . '<meta content="Milner 35+ Jong Kaas Stuk 450 g" property="og:title">'
        . '<meta content="https://www.jumbo.com/dam-images/kaas.png" property="og:image">'
        . '</head><body>'
        . jumboPriceComponent('Prijs: € 7,59', '7', '59')
        . '</body></html>';

    $result = $this->adapter->extract('https://www.jumbo.com/producten/milner-194089STK', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('7.59')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Milner 35+ Jong Kaas Stuk 450 g')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.jumbo.com/dam-images/kaas.png');
});

test('rebuilds the price from whole + fractional spans when the screenreader div is empty', function (): void {
    $html = '<html><body>'
        . '<h1>HiPRO Protein Drink</h1>'
        . jumboPriceComponent('', '14', '34')
        . '</body></html>';

    $result = $this->adapter->extract('https://jumbo.com/producten/hipro-494984DSL', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34')
        ->and($result->snapshot?->title)->toBe('HiPRO Protein Drink');
});

test('ignores card prices elsewhere on the page — only the product-price component counts', function (): void {
    $html = '<html><body>'
        . '<div class="jum-price right price"><div class="current-price"><div class="screenreader-only">Prijs: € 2,39</div></div></div>'
        . jumboPriceComponent('Prijs: € 14,34', '14', '34')
        . '</body></html>';

    $result = $this->adapter->extract('https://jumbo.com/producten/hipro-494984DSL', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('14.34');
});

test('failed when jumbo page has neither JSON-LD nor the price component', function (): void {
    $result = $this->adapter->extract('https://jumbo.com/producten/broken', '<html><body><p>x</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jumbo_extraction_failed');
});
