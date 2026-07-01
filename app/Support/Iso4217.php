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

    public static function isValid(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, strict: true);
    }
}
