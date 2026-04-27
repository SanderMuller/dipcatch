<?php declare(strict_types=1);

use App\Services\Scraper\PriceParser;

dataset('price_strings', [
    'EUR thousands + decimal' => ['€ 1.299,95', '1299.95'],
    'USD thousands + decimal' => ['$1,299.95', '1299.95'],
    'EUR with code prefix' => ['EUR 49,00', '49.00'],
    'GBP first of two prices' => ['Now £39.99 was £59', '39.99'],
    'EUR suffix' => ['1299.95 €', '1299.95'],
    'JPY no decimals' => ['¥12,800', '12800'],
    'PLN with thousands sep' => ['1 299,95 zł', '1299.95'],
    'plain integer' => ['42', '42'],
    'plain decimal dot' => ['12.34', '12.34'],
    'plain decimal comma' => ['12,34', '12.34'],
    'three-digit tail (thousands)' => ['1.234', '1234'],
    'three-digit tail comma (thousands)' => ['12,345', '12345'],
    'leading whitespace' => ["\t  €99,00\n", '99.00'],
    'sale + crossed out' => ['€39,95 was €59,95', '39.95'],
]);

test('PriceParser normalizes raw strings to canonical decimal strings', function (string $raw, string $expected): void {
    expect(new PriceParser()->parse($raw))->toBe($expected);
})->with('price_strings');

test('PriceParser returns null when no number is present', function (): void {
    expect(new PriceParser()->parse('Out of stock'))->toBeNull()
        ->and(new PriceParser()->parse(''))->toBeNull();
});

test('PriceParser handles a negative number', function (): void {
    expect(new PriceParser()->parse('-12,50'))->toBe('-12.50');
});
