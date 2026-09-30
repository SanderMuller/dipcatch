<?php declare(strict_types=1);

namespace App\Actions\Shops;

/**
 * The claimed regular price a reading keeps, if any: the price the shop says
 * it charged before the discount. See specs/category-expansion.md, section 3.
 */
final class RegularPriceClaim
{
    /**
     * Null when there is nothing to claim against. A claim at or below the
     * shelf price is no discount, and while a multi-buy bundle applies the
     * shop's "was" is the single-item price, which `single_item_price`
     * already holds.
     */
    public static function kept(?string $claim, ?string $shelfPrice, bool $bundleApplies): ?string
    {
        if (! is_numeric($claim) || ! is_numeric($shelfPrice) || $bundleApplies) {
            return null;
        }

        return bccomp($claim, $shelfPrice, 2) === 1 ? $claim : null;
    }
}
