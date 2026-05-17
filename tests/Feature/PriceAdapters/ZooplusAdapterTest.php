<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\ZooplusAdapter;

beforeEach(function (): void {
    $this->adapter = new ZooplusAdapter();
});

test('skips when host does not match', function (): void {
    $result = $this->adapter->extract('https://example.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('extracts the active variant price from a real zooplus page', function (): void {
    $html = (string) file_get_contents(base_path('tests/Fixtures/scraper/zooplus_feliway.html'));

    $result = $this->adapter->extract(
        'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.19',
        $html,
    );

    // Variant .19 = "Voordeelset: 3 navulflessen à 48 ml" at €58,99.
    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('58.99')
        ->and($result->snapshot?->currency)->toBe('EUR');
});

test('falls back to first reducedPriceAmount when no active variant class present', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1 data-zta="ProductTitle__Title">Single product</h1>
  <span class="z-product-price__amount" data-zta="reducedPriceAmount">€ 12,34</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://www.zooplus.de/shop/foo', $html);

    expect($result->snapshot?->price)->toBe('12.34')
        ->and($result->snapshot?->title)->toBe('Single product');
});

test('matches all zooplus country TLDs', function (string $url): void {
    $html = '<span data-zta="reducedPriceAmount">€ 9,99</span>';
    $result = $this->adapter->extract($url, $html);

    expect($result->isSuccess())->toBeTrue();
})->with([
    'nl' => ['https://www.zooplus.nl/shop/foo'],
    'de' => ['https://www.zooplus.de/shop/foo'],
    'be' => ['https://www.zooplus.be/shop/foo'],
    'com' => ['https://www.zooplus.com/shop/foo'],
]);
