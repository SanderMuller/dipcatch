<?php declare(strict_types=1);

use App\Support\MoneyFormatter;

dataset('money_strings', [
    'EUR uses the euro symbol' => ['1.69', 'EUR', '€1.69'],
    'USD groups thousands with a comma' => ['1234.56', 'USD', '$1,234.56'],
    'GBP uses the pound symbol' => ['0.99', 'GBP', '£0.99'],
    'JPY has zero minor units' => ['200.00', 'JPY', '¥200'],
    'CHF has no symbol, so the code is used' => ['1.69', 'CHF', 'CHF 1.69'],
    'a code outside Iso4217::CODES falls back' => ['1.69', 'ZZZ', 'ZZZ 1.69'],
    'an empty code renders the bare amount' => ['1.69', '', '1.69'],
    'a lowercase code is upper-cased first' => ['1.69', 'eur', '€1.69'],
    'a negative amount keeps intl sign placement' => ['-1.69', 'EUR', '-€1.69'],
    'a zero amount keeps two decimals' => ['0', 'EUR', '€0.00'],
]);

test('MoneyFormatter::format renders symbol-first, dot-decimal money', function (string $amount, string $currency, string $expected): void {
    expect(MoneyFormatter::format($amount, $currency))->toBe($expected);
})->with('money_strings');

test('a code with no intl symbol is separated by an ASCII space, never U+00A0', function (): void {
    $formatted = MoneyFormatter::format('1.69', 'CHF');

    expect($formatted)->toBe('CHF 1.69')
        ->and(str_contains($formatted, "\u{00A0}"))->toBeFalse()
        ->and(str_contains($formatted, "\u{202F}"))->toBeFalse();
});

test('a null amount renders an em dash', function (): void {
    expect(MoneyFormatter::format(null, 'EUR'))->toBe('—');
});

test('a non-numeric amount renders an em dash instead of throwing', function (): void {
    expect(MoneyFormatter::format('n/a', 'EUR'))->toBe('—');
});

test('MoneyFormatter::symbol returns the intl symbol, or the code when there is none', function (): void {
    expect(MoneyFormatter::symbol('EUR'))->toBe('€')
        ->and(MoneyFormatter::symbol('USD'))->toBe('$')
        ->and(MoneyFormatter::symbol('CHF'))->toBe('CHF')
        ->and(MoneyFormatter::symbol('eur'))->toBe('€')
        ->and(MoneyFormatter::symbol('ZZZ'))->toBe('ZZZ');
});

test('the shared formatters do not leak state between symbol() and format()', function (): void {
    // symbol() mutates its own NumberFormatter; format() must not see that.
    expect(MoneyFormatter::symbol('EUR'))->toBe('€')
        ->and(MoneyFormatter::format('1.69', 'USD'))->toBe('$1.69')
        ->and(MoneyFormatter::symbol('CHF'))->toBe('CHF')
        ->and(MoneyFormatter::format('200', 'JPY'))->toBe('¥200')
        ->and(MoneyFormatter::format('1.69', 'EUR'))->toBe('€1.69');
});
