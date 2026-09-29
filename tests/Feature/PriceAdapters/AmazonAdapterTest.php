<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\AmazonAdapter;

beforeEach(function (): void {
    $this->adapter = new AmazonAdapter();
});

test('skips when host is not an amazon TLD', function (): void {
    $result = $this->adapter->extract('https://other.com/dp/B0000', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('maps each TLD to the expected currency', function (string $host, string $currency): void {
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">10,00</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract("https://{$host}/dp/B000", $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->currency)->toBe($currency);
})->with([
    ['amazon.nl', 'EUR'],
    ['www.amazon.nl', 'EUR'],
    ['amazon.com', 'USD'],
    ['amazon.co.uk', 'GBP'],
    ['amazon.de', 'EUR'],
    ['amazon.co.jp', 'JPY'],
    ['amazon.ca', 'CAD'],
]);

test('resolves overlapping suffixes to the most specific TLD', function (): void {
    // `amazon.com` and `amazon.com.au` both live in the host map; a subdomain
    // of the longer one must not collapse onto the shorter one's currency.
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">10,00</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://smile.amazon.com.au/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->currency)->toBe('AUD');
});

test('matches subdomains via host suffix', function (): void {
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">$10.00</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://smile.amazon.com/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('delegates to JsonLdAdapter when the page exposes schema.org markup', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Echo Dot',
        'offers' => [
            '@type' => 'Shop',
            'price' => '49.99',
            'priceCurrency' => 'USD',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://amazon.com/dp/B0000', withJsonLd($json));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('49.99')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('falls back to corePriceDisplay CSS selector', function (): void {
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Sony WH-1000XM4</span>
  <img id="landingImage" src="https://m.media-amazon.com/img.jpg" />
  <div id="corePriceDisplay_desktop_feature_div">
    <span class="a-price"><span class="a-offscreen">€289,99</span></span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.nl/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('289.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Sony WH-1000XM4')
        ->and($result->snapshot?->imageUrl)->toBe('https://m.media-amazon.com/img.jpg');
});

test('falls back to priceblock_ourprice for legacy layouts', function (): void {
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Legacy</span>
  <span id="priceblock_ourprice">$19.99</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.com/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('19.99')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('parses image url from data-a-dynamic-image when src is missing', function (): void {
    $dynamic = htmlspecialchars(
        json_encode([
            'https://m.media-amazon.com/big.jpg' => [500, 500],
            'https://m.media-amazon.com/small.jpg' => [250, 250],
        ], JSON_THROW_ON_ERROR),
        ENT_QUOTES,
    );

    $html = <<<HTML
<html><body>
  <span id="productTitle">Demo</span>
  <img id="landingImage" data-a-dynamic-image="{$dynamic}" />
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">10,00</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.nl/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://m.media-amazon.com/big.jpg');
});

test('picks the live price when a struck-through list price shares the container', function (): void {
    // Real Amazon PDPs render the basis (list) price before the buy-box
    // price inside the same `#corePriceDisplay_desktop_feature_div`. A naive
    // `.a-offscreen` -> first() would grab the strikethrough.
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div">
    <span class="a-price a-text-price" data-a-color="secondary"><span class="a-offscreen">€99,99</span></span>
    <span class="a-price priceToPay" data-a-color="base"><span class="a-offscreen">€59,99</span></span>
  </div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.nl/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('59.99');
});

test('marks out-of-stock when availability uses the red color class', function (): void {
    // Locale-independent: Amazon paints unavailable messages with
    // `.a-color-price` across every supported TLD, so we recognise this
    // regardless of the surrounding language.
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">10,00</span></div>
  <div id="availability"><span class="a-size-medium a-color-price">在庫切れです。</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.co.jp/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->inStock)->toBeFalse();
});

test('marks out-of-stock when availability text matches a known phrase', function (): void {
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div id="corePriceDisplay_desktop_feature_div"><span class="a-offscreen">10,00</span></div>
  <div id="availability"><span>Currently unavailable.</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.com/dp/B000', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->inStock)->toBeFalse();
});

test('failed when amazon page has no recognizable price', function (): void {
    $html = '<html><body><p>broken</p></body></html>';

    $result = $this->adapter->extract('https://amazon.com/dp/B000', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('amazon_extraction_failed');
});

test('ignores related-product .a-offscreen prices outside the buy box', function (): void {
    // Bare `.a-offscreen` from a sponsored card should NOT be picked up — the
    // adapter only matches scoped buy-box containers.
    $html = <<<'HTML'
<html><body>
  <span id="productTitle">Demo</span>
  <div class="sponsored"><span class="a-offscreen">€999,99</span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://amazon.nl/dp/B000', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('amazon_extraction_failed');
});

test('names a page without a featured offer apart from a layout it cannot read', function (string $marker): void {
    // Seen on 2026-09-25: amazon.nl showing only "See All Buying Options",
    // and amazon.com shipping nothing to the visitor's address.
    $html = <<<HTML
<html><body>
  <span id="productTitle">CeraVe Moisturising Cream</span>
  <div id="desktop_buybox">{$marker}</div>
  <div class="a-carousel-card"><span class="a-price"><span class="a-offscreen">€1492</span></span></div>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.amazon.nl/dp/B0923725WZ', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('amazon_no_featured_offer');
})->with([
    'no buy box' => ['<div id="unqualifiedBuyBox"></div>'],
    'all buying options' => ['<span id="buybox-see-all-buying-choices">See All Buying Options</span>'],
    'nothing ships here' => ['<span>No featured offers available</span>'],
]);

test('keeps the generic failure for a page it simply cannot read', function (): void {
    $result = $this->adapter->extract('https://www.amazon.nl/dp/B000', '<html><body><span id="productTitle">Demo</span></body></html>');

    expect($result->failureReason)->toBe('amazon_extraction_failed');
});
