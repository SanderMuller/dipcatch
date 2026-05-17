<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Shared decimal-string normalization for adapters. Accepts strings or
 * numbers, handles both European ("1.234,56") and US ("1,234.56") thousand
 * separators, returns either a numeric-string or null.
 */
final class PriceNormalizer
{
    public static function fromMixed(mixed $value): ?string
    {
        if (is_string($value)) {
            $cleaned = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
            $cleaned = self::canonicalizeDecimal($cleaned);

            return is_numeric($cleaned) ? $cleaned : null;
        }

        if (is_int($value) || is_float($value)) {
            $str = self::canonicalizeDecimal((string) $value);

            return is_numeric($str) ? $str : null;
        }

        return null;
    }

    /**
     * Normalize ambiguous decimal separators: prefer '.' as the decimal sep.
     *  - "1.234,56" → "1234.56"
     *  - "1,234.56" → "1234.56"
     *  - "1234.56"  → "1234.56"
     *  - "1234,56"  → "1234.56" (when tail = 2 digits)
     *  - "1,234"    → "1234"    (when tail = 3 digits, treat as thousands)
     */
    public static function canonicalizeDecimal(string $value): string
    {
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma && ! $hasDot) {
            $tail = substr($value, strrpos($value, ',') + 1);
            if (strlen($tail) === 2) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        return $value;
    }
}
