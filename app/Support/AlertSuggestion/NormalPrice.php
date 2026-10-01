<?php declare(strict_types=1);

namespace App\Support\AlertSuggestion;

use App\Actions\Shops\RegularPriceClaim;
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
     * @param  numeric-string  $price  The pack price.
     * @param  int  $depthNow  Whole percent under `$price` the shop charges now.
     */
    private function __construct(
        public string $price,
        public int $depthNow,
    ) {}

    /**
     * Null when the shop's normal price cannot be told: no price, a running
     * promotion with no "was" price, or a "was" price `$trustClaims` says not
     * to believe.
     */
    public static function of(Shop $shop, bool $trustClaims): ?self
    {
        $current = $shop->current_price;

        if (! is_numeric($current)) {
            return null;
        }

        $current = Numeric::str((string) $current);
        $bundle = $shop->liveBundleOffer();

        if ($bundle !== null) {
            $single = Numeric::str((string) $shop->singleItemPrice());

            return new self($single, self::depth($bundle->effectiveUnitPrice(), $single));
        }

        $window = $shop->promotionWindow();

        // An announced bonus that has not started: the price on screen is
        // still the regular one.
        if ($window?->hasNotStarted() === true) {
            return new self($current, 0);
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

            return $trustClaims ? new self($claim, self::depth($current, $claim)) : null;
        }

        if ($window?->isRunning() === true || ($window === null && $shop->promotion_label !== null)) {
            return null;
        }

        return new self($current, 0);
    }

    /** @param numeric-string $normal */
    private static function depth(string $now, string $normal): int
    {
        if (bccomp($normal, '0', 2) <= 0) {
            return 0;
        }

        return max(0, (int) bcmul(bcsub('1', bcdiv(Numeric::str($now), $normal, 10), 10), '100', 0));
    }
}
