<?php declare(strict_types=1);

namespace App\Support;

/**
 * The sums behind the alert helpers: a price for my pack, a percentage under
 * today's best, an amount under my pack's price. Each one gives the target
 * per kilo, litre, piece or pack that is stored.
 *
 * Decimal strings throughout, and every result cut, never rounded up, to the
 * four decimals the column keeps: a target rounded up can be met by a price
 * whose true value is above it.
 */
final class UnitTargetMath
{
    private const int SCALE = 10;

    /**
     * How many comparison units one pack holds: 1.5 for a 1.5 L bottle
     * compared per litre, 8 for a box of 8 compared per piece.
     *
     * @return numeric-string
     */
    public static function perPack(PackSize $size): string
    {
        $quantity = self::decimal($size->quantity);

        return $size->unit === 'piece' ? $quantity : bcdiv($quantity, '1000', self::SCALE);
    }

    /**
     * The target a price for a pack of `$perPack` units comes to: €22.80 for
     * 660 g is €34.5454 a kilo. A target per pack passes `'1'`.
     *
     * @param  numeric-string  $perPack
     * @return numeric-string|null
     */
    public static function fromPackPrice(string $packPrice, string $perPack): ?string
    {
        if (! is_numeric($packPrice) || bccomp($perPack, '0', self::SCALE) <= 0) {
            return null;
        }

        return self::storable(bcdiv(self::plain($packPrice), $perPack, self::SCALE));
    }

    /**
     * `$percent` under a pack's price, per unit. The saving comes off the
     * pack price before the division, as the presets on the form work it
     * out: 25% off €1.00 for 12 is €0.0625 each, not €0.0624 from a price per
     * piece already cut to four decimals. Null outside 0–100 exclusive.
     *
     * @param  numeric-string  $perPack
     * @return numeric-string|null
     */
    public static function percentUnderPack(string $packPrice, string $percent, string $perPack): ?string
    {
        if (! is_numeric($packPrice) || ! is_numeric($percent) || (float) $percent <= 0 || (float) $percent >= 100) {
            return null;
        }

        $share = bcsub('1', bcdiv(self::plain($percent), '100', self::SCALE), self::SCALE);

        return self::fromPackPrice(bcmul(self::plain($packPrice), $share, self::SCALE), $perPack);
    }

    /**
     * `$amount` off a pack's price, per unit. Null when the amount is not
     * positive, or takes the whole price.
     *
     * @param  numeric-string  $perPack
     * @return numeric-string|null
     */
    public static function amountUnder(string $packPrice, string $amount, string $perPack): ?string
    {
        if (! is_numeric($packPrice) || ! is_numeric($amount) || (float) $amount <= 0
            || bccomp(self::plain($amount), self::plain($packPrice), self::SCALE) >= 0) {
            return null;
        }

        return self::fromPackPrice(bcsub(self::plain($packPrice), self::plain($amount), self::SCALE), $perPack);
    }

    /**
     * A float as a decimal string bc math can read without the float's noise:
     * 4.2 stays 4.2, not 4.1999.
     *
     * @return numeric-string
     */
    public static function decimal(float $value): string
    {
        return Numeric::str(sprintf('%.' . self::SCALE . 'F', $value));
    }

    /**
     * A numeric input as bc math reads it: "1e1" passes `is_numeric()` and
     * validation, and bc math refuses it.
     *
     * @return numeric-string
     */
    private static function plain(string $value): string
    {
        return stripos($value, 'e') === false ? Numeric::str($value) : self::decimal((float) $value);
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string|null
     */
    private static function storable(string $value): ?string
    {
        $value = bcadd($value, '0', PackSize::VALUE_DECIMALS);

        // Outside what `unit_price_target` (decimal 12,4) can hold.
        if (bccomp($value, '0.0001', PackSize::VALUE_DECIMALS) < 0 || bccomp($value, '99999999.9999', PackSize::VALUE_DECIMALS) > 0) {
            return null;
        }

        return $value;
    }
}
