<?php declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Renders money as symbol-first, dot-decimal strings: `€1.69`, `$1,234.56`,
 * `¥200`, `CHF 1.69`. One rule for every surface — tables, infolists, mail,
 * push bodies, chart labels and the public share page.
 */
final class MoneyFormatter
{
    /**
     * Worker-local formatter for {@see format()}. `formatCurrency()` takes the
     * code per call, so no per-request state lives on this instance.
     */
    private static ?NumberFormatter $amountFormatter = null;

    /**
     * Separate worker-local formatter for {@see symbol()}. It gets mutated by
     * `setTextAttribute()`, so it must never be the instance `format()` uses.
     */
    private static ?NumberFormatter $symbolFormatter = null;

    public static function format(?string $amount, string $currency): string
    {
        if ($amount === null || ! is_numeric($amount)) {
            return '—';
        }

        $code = strtoupper(trim($currency));
        $value = (float) $amount;

        if (! Iso4217::isValid($code)) {
            return self::fallback($code, $value);
        }

        $formatted = self::amountFormatter()->formatCurrency($value, $code);

        if ($formatted === false) {
            return self::fallback($code, $value);
        }

        return self::normaliseSpaces($formatted);
    }

    /**
     * The currency symbol intl uses — `€`, `$`, `£` — or the code itself when
     * intl has no symbol for it (`CHF`) or the code is not one we support.
     */
    public static function symbol(string $currency): string
    {
        $code = strtoupper(trim($currency));

        if (! Iso4217::isValid($code)) {
            return $code;
        }

        $formatter = self::symbolFormatter();
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code);
        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        if ($symbol === false || $symbol === '') {
            return $code;
        }

        return self::normaliseSpaces($symbol);
    }

    /**
     * Used for codes intl cannot be trusted with (not in `Iso4217::CODES`) and
     * as a last-resort guard for a broken ICU build. Never renders `¤`.
     */
    private static function fallback(string $code, float $value): string
    {
        $number = number_format($value, 2, '.', ',');

        return $code === '' ? $number : $code . ' ' . $number;
    }

    /**
     * ICU separates a code from its amount with U+00A0 (and, in some locales,
     * U+202F). One byte-stable ASCII space keeps HTML, plain-text mail, push
     * bodies and test assertions in agreement.
     */
    private static function normaliseSpaces(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
    }

    private static function amountFormatter(): NumberFormatter
    {
        return self::$amountFormatter ??= new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    }

    private static function symbolFormatter(): NumberFormatter
    {
        return self::$symbolFormatter ??= new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    }
}
