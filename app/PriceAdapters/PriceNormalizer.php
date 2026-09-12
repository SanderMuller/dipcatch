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
     *  - "1,234"    → "1234"    (a 3-digit tail after "1" is a thousands group)
     *  - "0,899"    → "0.899"   (a 3-digit tail after "0" is a decimal)
     *  - "1,2345"   → "1,2345"  (no price shape, left for the caller to refuse)
     *  - "0.899"    → "0.899"   (same rule, so this one is not ambiguous)
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
            $position = (int) strrpos($value, ',');
            $tail = substr($value, $position + 1);

            // A tail of 1 to 3 digits is a decimal ("1,2" is €1.20, not €12)
            // unless the head can carry a thousands group. A bare trailing
            // comma is noise, and a longer tail is no price shape at all.
            $value = match (true) {
                $tail === '', self::isThousandsSeparator($value, $position) => str_replace(',', '', $value),
                strlen($tail) <= 3 => str_replace(',', '.', $value),
                default => $value,
            };
        } elseif ($hasDot) {
            // A thousands separator here cannot be told apart from three
            // decimals: "1.099" is €1099 in one locale and €1.099 in another,
            // and nothing says which one the shop wrote. Refusing costs a
            // parse error; guessing costs a price that is 1000x wrong.
            if (self::isThousandsSeparator($value, (int) strrpos($value, '.'))) {
                return '';
            }
        }

        return $value;
    }

    /**
     * Whether the separator at `$position` groups thousands, rather than
     * marking the decimal point.
     *
     * A group is three characters long, and the number that carries one never
     * starts with a zero. No locale writes "0,899" or ",899" for 899, so a
     * zero-led or empty integer part rules the grouped reading out. The sign
     * is not part of that test, or "-0,899" would disagree with "0,899".
     */
    private static function isThousandsSeparator(string $value, int $position): bool
    {
        $head = ltrim(substr($value, 0, $position), '-');

        return strlen(substr($value, $position + 1)) === 3
            && $head !== ''
            && ! str_starts_with($head, '0');
    }
}
