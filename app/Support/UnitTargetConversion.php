<?php declare(strict_types=1);

namespace App\Support;

/**
 * Expresses a per-unit figure the owner set in one unit in the unit the
 * product compares in today.
 *
 * A target is a price per kilo, per litre or per piece, and the product's
 * comparison unit can change under it when a shop starts stating a second
 * size. The owner's own number is never rewritten: this class derives the
 * figure every comparison reads, and answers null when the two units do not
 * convert, so a target set per piece is never read as a price per kilo.
 */
final readonly class UnitTargetConversion
{
    /** The storage range of a per-unit figure: `decimal(12,4)`, and the floor the forms accept. */
    private const string MIN = '0.0001';

    private const string MAX = '99999999.9999';

    /**
     * The owner's target in the product's comparison unit.
     *
     * A target with no unit of its own is one set before units were recorded,
     * or before the product had a unit at all: it means the unit the product
     * compares in, as it always did.
     */
    public static function effective(?string $target, ?string $targetUnit, ComparablePacks $packs): ?string
    {
        if ($target === null || ! is_numeric($target)) {
            return null;
        }

        $unit = $packs->unit();

        if ($targetUnit === null || $targetUnit === $unit) {
            return self::stored((float) $target);
        }

        // A target with a unit, on a product that compares in none: nothing
        // to read it against, so it is paused like one that does not convert.
        if ($unit === null) {
            return null;
        }

        return self::convert($target, $targetUnit, $unit, $packs);
    }

    /**
     * A per-unit figure moved from one unit to another, at full precision and
     * then cut to what the column keeps. Null when no item size links the two
     * units, or when the result falls outside the storage range.
     */
    public static function convert(string $value, string $from, string $to, ComparablePacks $packs): ?string
    {
        $converted = self::convertValue((float) $value, $from, $to, $packs);

        return $converted === null ? null : self::stored($converted);
    }

    /** The same conversion, unrounded, for a comparison that must not drift. */
    public static function convertValue(float $value, string $from, string $to, ComparablePacks $packs): ?float
    {
        $itemSize = $packs->itemSizeBetween($from, $to);

        if ($itemSize === null) {
            return null;
        }

        // Per kilo or per litre is per 1000 g or ml: a piece of 28 g at €0.25
        // is €0.25 / 28 × 1000 = €8.9286 a kilo.
        return $from === 'piece'
            ? $value / $itemSize * 1000
            : $value * $itemSize / 1000;
    }

    private static function stored(float $value): ?string
    {
        return self::inRange($value) ? number_format($value, PackSize::VALUE_DECIMALS, '.', '') : null;
    }

    /** Whether a per-unit figure, at four decimals, fits the range a target is stored in. */
    public static function inRange(float $value): bool
    {
        $formatted = number_format($value, PackSize::VALUE_DECIMALS, '.', '');

        return bccomp($formatted, self::MIN, PackSize::VALUE_DECIMALS) >= 0 && bccomp($formatted, self::MAX, PackSize::VALUE_DECIMALS) <= 0;
    }
}
