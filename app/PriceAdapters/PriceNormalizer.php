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
            // A number carries no locale, so its dot is always the decimal
            // point. The separator rules below only resolve text, and applying
            // them here would refuse an unambiguous value such as 1.099.
            if (is_float($value) && ! is_finite($value)) {
                return null;
            }

            return (string) $value;
        }

        return null;
    }

    /**
     * Normalize ambiguous decimal separators: prefer '.' as the decimal sep.
     *  - "1.234,56" → "1234.56"
     *  - "1,234.56" → "1234.56"
     *  - "1234.56"  → "1234.56"
     *  - "1234,56"  → "1234.56" (when tail = 1 or 2 digits)
     *  - "1,234"    → "1234"    (when tail = 3 digits, treat as thousands)
     *  - "1,2345"   → "1,2345"  (no price shape, left for the caller to refuse)
     *  - "1.099"    → ""        (ambiguous, so the caller reads it as a failure)
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
        } elseif ($hasComma) {
            $tail = substr($value, strrpos($value, ',') + 1);

            // 1 or 2 digits is a decimal ("1,2" is €1.20, not €12), 3 is a
            // thousands group, and a bare trailing comma is noise. Any other
            // length is no price shape, so leave it for is_numeric() to refuse.
            $value = match (strlen($tail)) {
                0 => str_replace(',', '', $value),
                1, 2 => str_replace(',', '.', $value),
                3 => str_replace(',', '', $value),
                default => $value,
            };
        } elseif ($hasDot) {
            $tail = substr($value, strrpos($value, '.') + 1);

            // A 3-digit dot tail is the European thousands form ("1.099" is
            // €1099) or three decimals, and nothing here says which one the
            // shop wrote. Unlike a comma, neither reading is the common one.
            // Refusing costs a parse error; guessing costs a 1000x wrong price.
            if (strlen($tail) === 3) {
                return '';
            }
        }

        return $value;
    }
}
