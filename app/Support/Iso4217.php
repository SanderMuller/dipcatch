<?php declare(strict_types=1);

namespace App\Support;

final class Iso4217
{
    /**
     * Currency codes the app surfaces in dropdowns. Not exhaustive — extend
     * as needed when users report missing currencies.
     *
     * @var list<string>
     */
    public const array CODES = [
        'EUR', 'USD', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD',
        'SEK', 'DKK', 'NOK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN',
        'TRY', 'CNY', 'HKD', 'SGD', 'KRW', 'TWD', 'INR', 'BRL',
        'MXN', 'ZAR', 'AED', 'SAR', 'ILS',
    ];

    /**
     * @return array<string, string> code => code
     */
    public static function options(): array
    {
        return array_combine(self::CODES, self::CODES);
    }

    /**
     * A storable currency code, or null when the value is not one.
     *
     * The `currency` columns are `char(3)`. A shop publishing `" EUR "` or
     * `"Euro"` used to reach the insert unchanged, which SQLite accepted and
     * Postgres refused with "value too long for type character(3)" — the
     * recheck then failed for that shop on every run.
     */
    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return preg_match('/^[A-Z]{3}$/', $normalized) === 1 ? $normalized : null;
    }

    public static function isValid(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, strict: true);
    }
}
