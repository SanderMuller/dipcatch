<?php declare(strict_types=1);

use App\Support\LocaleCurrency;

dataset('locale_currency_pairs', [
    ['nl-NL,nl;q=0.9', 'EUR'],
    ['en-US,en;q=0.8', 'USD'],
    ['en-GB,en;q=0.5', 'GBP'],
    ['en;q=1', 'USD'],
    ['ja-JP', 'JPY'],
    ['pl-PL,pl;q=0.9', 'PLN'],
    ['zh-TW,zh;q=0.9,en;q=0.5', 'TWD'],
    ['de-CH,de;q=0.9', 'EUR'],
    ['xx-XX', 'EUR'],
    ['', 'EUR'],
    [null, 'EUR'],
]);

test('LocaleCurrency::guess maps locale to currency', function (?string $header, string $expected): void {
    expect(LocaleCurrency::guess($header))->toBe($expected);
})->with('locale_currency_pairs');

test('LocaleCurrency::guess respects q-value ordering', function (): void {
    // Lower-q en-US would lose to higher-q nl-NL.
    expect(LocaleCurrency::guess('en-US;q=0.5,nl-NL;q=0.9'))->toBe('EUR');
});

test('custom fallback used when no entry matches', function (): void {
    expect(LocaleCurrency::guess('xx-XX', fallback: 'CHF'))->toBe('CHF');
});
