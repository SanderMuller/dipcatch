<?php declare(strict_types=1);

use App\Services\Scraper\CurrencyDetector;
use Symfony\Component\DomCrawler\Crawler;

dataset('symbol_pairs', [
    'euro symbol' => ['€ 1.299,95', 'EUR'],
    'dollar symbol' => ['$1,299.95', 'USD'],
    'pound symbol' => ['£39.99', 'GBP'],
    'yen symbol' => ['¥12,800', 'JPY'],
    'iso code' => ['EUR 49,00', 'EUR'],
    'PLN suffix' => ['1 299,95 zł', 'PLN'],
    'CZK suffix' => ['250 Kč', 'CZK'],
    'BRL prefix' => ['R$ 89,00', 'BRL'],
    'AUD prefix' => ['A$ 49.00', 'AUD'],
    'no signal' => ['1234', null],
]);

test('CurrencyDetector detects from a raw string', function (string $raw, ?string $expected): void {
    expect(new CurrencyDetector()->detectFromString($raw))->toBe($expected);
})->with('symbol_pairs');

test('CurrencyDetector prefers the matching hint when multiple currencies appear', function (): void {
    $detector = new CurrencyDetector();

    expect($detector->detectFromString('€89.00 / $99.00', preferred: 'USD'))->toBe('USD')
        ->and($detector->detectFromString('€89.00 / $99.00', preferred: 'EUR'))->toBe('EUR')
        ->and($detector->detectFromString('€89.00 / $99.00'))->toBe('EUR'); // first occurrence
});

test('CurrencyDetector falls back to first occurrence when hint does not appear', function (): void {
    expect(new CurrencyDetector()->detectFromString('€89.00 / $99.00', preferred: 'CHF'))->toBe('EUR');
});

test('CurrencyDetector falls back to meta priceCurrency tag', function (): void {
    $html = '<html><head><meta itemprop="priceCurrency" content="EUR"></head><body></body></html>';
    $crawler = new Crawler($html);

    expect(new CurrencyDetector()->detect('Loading…', $crawler))->toBe('EUR');
});

test('CurrencyDetector falls back to JSON-LD priceCurrency', function (): void {
    $html = '<html><head><script type="application/ld+json">{"@type":"Product","offers":{"price":"49.00","priceCurrency":"GBP"}}</script></head><body></body></html>';
    $crawler = new Crawler($html);

    expect(new CurrencyDetector()->detect('Loading…', $crawler))->toBe('GBP');
});

test('CurrencyDetector returns null when nothing matches', function (): void {
    $crawler = new Crawler('<html><body></body></html>');

    expect(new CurrencyDetector()->detect('Loading…', $crawler))->toBeNull();
});
