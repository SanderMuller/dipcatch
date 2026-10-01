<?php declare(strict_types=1);

namespace App\Support\AlertSuggestion;

use App\Actions\Shops\RegularPriceClaim;
use App\Enums\ProductDepartment;
use App\Models\Shop;
use App\Support\Numeric;

/**
 * What one shop charges when nothing is on offer, and how far under that its
 * price is now. A suggested alert measured from the price on screen would
 * ask a product already 25% off for another 30% on top.
 */
final readonly class NormalPrice
{
    /**
     * @param  numeric-string  $price  The normal pack price, before any offer.
     * @param  int  $depthNow  Whole percent under `$price` the offer is worth, rounded for display.
     * @param  int  $depthStep  The depth of the price the alert checks, floored to a step of 5%, so a target at it is never deeper than today's offer.
     */
    private function __construct(
        public string $price,
        public int $depthNow = 0,
        public int $depthStep = 0,
    ) {}

    /**
     * Null when the shop's normal price cannot be told: no price, a running
     * promotion with no "was" price, or a "was" price in a department where
     * those say little.
     */
    public static function of(Shop $shop, ?ProductDepartment $department): ?self
    {
        $current = $shop->current_price;

        if (! is_numeric($current)) {
            return null;
        }

        $current = Numeric::str((string) $current);
        $bundle = $shop->liveBundleOffer();

        if ($bundle !== null) {
            $single = Numeric::str((string) $shop->singleItemPrice());

            // From the bundle's total, not its per-item price: that is rounded
            // to the cent, which reads a 1+1 at €2.99 as 49% off.
            // The offer's worth from its total; the step from the cent-rounded
            // price per item the alert checks, which a 1+1 on €2.99 puts at €1.50.
            return self::under($single, bcdiv($bundle->totalPrice, (string) $bundle->quantity, 10), $current);
        }

        $window = $shop->promotionWindow();

        if ($window?->hasNotStarted() === true) {
            return new self($current);
        }

        // Kept earlier by a reader with claim authority, so checked again:
        // a later price rise can leave it at or under the shelf price.
        $claim = RegularPriceClaim::kept(
            $shop->claimed_regular_price === null ? null : (string) $shop->claimed_regular_price,
            $current,
            bundleApplies: false,
        );

        if ($claim !== null) {
            $claim = Numeric::str($claim);

            $distrusted = $department instanceof ProductDepartment && TypicalPromotionDepth::distrustsClaims($department);

            return $distrusted ? null : self::under($claim, $current, $current);
        }

        if ($window?->isRunning() === true || ($window === null && $shop->promotion_label !== null)) {
            return null;
        }

        return new self($current);
    }

    /** Whether the shop shows an offer now: a live multi-buy, a running promotion, or a "was" price above today's. */
    public static function isOnOffer(Shop $shop): bool
    {
        return $shop->liveBundleOffer() !== null
            || $shop->promotionWindow()?->isRunning() === true
            || ($shop->claimed_regular_price !== null && (float) $shop->claimed_regular_price > (float) $shop->current_price);
    }

    /**
     * @param  numeric-string  $normal
     * @param  numeric-string  $worth  The offer's price per item, unrounded.
     * @param  numeric-string  $tracked  The price the alert checks.
     */
    private static function under(string $normal, string $worth, string $tracked): self
    {
        if (bccomp($normal, '0', 2) <= 0) {
            return new self($normal);
        }

        return new self(
            $normal,
            max(0, (int) round(self::percentUnder($normal, $worth))),
            max(0, (int) floor(self::percentUnder($normal, $tracked) / 5) * 5),
        );
    }

    /**
     * @param  numeric-string  $normal
     * @param  numeric-string  $price
     */
    private static function percentUnder(string $normal, string $price): float
    {
        return (float) bcmul(bcsub('1', bcdiv($price, $normal, 10), 10), '100', 10);
    }
}
