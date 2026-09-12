<?php declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes a GTIN (EAN/UPC) read from shop markup.
 *
 * Shops publish the identifier under several field names and with stray
 * spaces or dashes. A stored value is only useful when it is certainly the
 * real identifier: the mismatch warning compares GTINs across a product's
 * shops, so a typo'd digit would raise a warning about nothing.
 */
final class Gtin
{
    /** GS1 lengths: GTIN-8, UPC-12, EAN-13, GTIN-14. */
    private const array LENGTHS = [8, 12, 13, 14];

    /**
     * Digits only, a GS1 length, and a valid check digit. Anything else is
     * null — a wrong value is worse than none.
     */
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        // Only separators may be stripped. Deleting every non-digit would
        // turn "8712ABC243044506" into a valid-looking GTIN — garbage in,
        // false mismatch warning out.
        $digits = str_replace([' ', '-', '.', "\u{00A0}"], '', trim($value));

        if ($digits === '' || ctype_digit($digits) === false) {
            return null;
        }

        if (! in_array(strlen($digits), self::LENGTHS, true)) {
            return null;
        }

        return self::checkDigitIsValid($digits) ? $digits : null;
    }

    /**
     * First valid GTIN among the schema.org field names, in GS1 preference
     * order, across the given entities.
     *
     * @param  array<int, array<string, mixed>|null>  $entities
     */
    public static function fromEntities(array $entities): ?string
    {
        foreach (['gtin13', 'gtin14', 'gtin12', 'gtin8', 'gtin'] as $field) {
            foreach ($entities as $entity) {
                $gtin = self::normalize($entity[$field] ?? null);

                if ($gtin !== null) {
                    return $gtin;
                }
            }
        }

        return null;
    }

    /**
     * GS1 check digit: weight the digits right-to-left by 3 and 1 in turn,
     * then the total plus the check digit must be a multiple of ten.
     */
    private static function checkDigitIsValid(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $check = (int) substr($digits, -1);

        $sum = 0;
        $weight = 3;

        foreach (array_reverse(str_split($body)) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
