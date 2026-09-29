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
    expect(MoneyFormatter::format(amount: null, currency: 'EUR'))->toBe('—');
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

// --- unit prices -------------------------------------------------------------

test('a unit price under one is shown at four decimals', function (): void {
    // A 400-tablet pack at 12.99 and an 800-tablet pack at 21.99 are 18%
    // apart and both rendered EUR 0.03.
    expect(MoneyFormatter::unitPrice('0.0325', 'EUR'))->toBe('€0.0325')
        ->and(MoneyFormatter::unitPrice('0.0275', 'EUR'))->toBe('€0.0275');
});

test('a unit price of one or more is shown as money', function (): void {
    // Nobody needs EUR 5.3784 per kilo.
    expect(MoneyFormatter::unitPrice('5.3784', 'EUR'))->toBe('€5.38')
        ->and(MoneyFormatter::unitPrice('33.5700', 'EUR'))->toBe('€33.57');
});

test('the precision follows the magnitude, not the unit', function (): void {
    // A price per piece can be eleven euros, and a price per kilo can be
    // twenty cents.
    expect(MoneyFormatter::unitPrice('11.0000', 'EUR'))->toBe('€11.00')
        ->and(MoneyFormatter::unitPrice('0.2000', 'EUR'))->toBe('€0.2000');
});

test('widening a unit price does not widen ordinary money', function (): void {
    // The two formatters share no instance: setting fraction digits on one
    // would otherwise widen every price on every surface.
    expect(MoneyFormatter::unitPrice('0.0325', 'EUR'))->toBe('€0.0325')
        ->and(MoneyFormatter::format('1.69', 'EUR'))->toBe('€1.69');
});

test('a currency intl cannot render still gets the extra decimals', function (): void {
    expect(MoneyFormatter::unitPrice('0.0275', 'XYZ'))->toBe('XYZ 0.0275');
});

test('a unit price that is not a number renders as a dash', function (): void {
    expect(MoneyFormatter::unitPrice(amount: null, currency: 'EUR'))->toBe('—')
        ->and(MoneyFormatter::unitPrice('abc', 'EUR'))->toBe('—');
});
