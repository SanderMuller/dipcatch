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

    /** Third worker-local formatter — see {@see preciseFormatter()}. */
    private static ?NumberFormatter $preciseFormatter = null;

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
     * Currencies Stripe states without a minor unit: JPY 500 is the string
     * `500`, not 5.00. Dividing those by 100 undercharges the reader by a
     * factor of a hundred.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const array ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Stripe states money in the currency's minor unit — 499 is EUR 4.99.
     * Converts and formats in one step, so no billing surface hand-rolls
     * the division.
     */
    public static function formatMinor(int $amount, string $currency): string
    {
        $code = strtoupper(trim($currency));

        return in_array($code, self::ZERO_DECIMAL, strict: true)
            ? self::format((string) $amount, $code)
            : self::format(sprintf('%.2F', $amount / 100), $code);
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
     * A price per kilo, litre or piece, with enough decimals to tell two
     * shops apart.
     *
     * Two decimals are right for a price per kilo and useless for a price per
     * tablet: a 400-pack and an 800-pack of the same tablet are 18% apart and
     * both render `€0.03`. Below one unit of currency the figure is shown at
     * four decimals, which is also the scale it is stored and compared at.
     * Above it, two — nobody needs `€5.3784 /kg`.
     *
     * The threshold is the magnitude, not the unit. A price per piece can be
     * eleven euros and a price per kilo can be twenty cents.
     */
    public static function unitPrice(?string $amount, string $currency): string
    {
        if ($amount === null || ! is_numeric($amount)) {
            return '—';
        }

        $value = (float) $amount;

        if (abs($value) >= 1.0) {
            return self::format($amount, $currency);
        }

        $code = strtoupper(trim($currency));

        if (! Iso4217::isValid($code)) {
            return trim($code . ' ' . number_format($value, PackSize::VALUE_DECIMALS, '.', ','));
        }

        $formatted = self::preciseFormatter()->formatCurrency($value, $code);

        return $formatted === false
            ? self::fallback($code, $value)
            : self::normaliseSpaces($formatted);
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

    /**
     * Its own instance, for the same reason {@see $symbolFormatter} is one:
     * the fraction-digit attributes are set on it, and sharing the instance
     * {@see format()} uses would widen every price on every surface.
     */
    private static function preciseFormatter(): NumberFormatter
    {
        if (self::$preciseFormatter === null) {
            $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, PackSize::VALUE_DECIMALS);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, PackSize::VALUE_DECIMALS);
            self::$preciseFormatter = $formatter;
        }

        return self::$preciseFormatter;
    }
}
