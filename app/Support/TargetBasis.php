<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;

/**
 * What a product's target compares with right now: a price per kilo, litre or
 * piece at the best-value shop, or the price of one pinned shop's pack.
 *
 * One answer for the detector, the latch and the at-target checks, so none
 * of them decides on its own which price a target means.
 */
final readonly class TargetBasis
{
    /**
     * @param  string  $unit  `g`, `ml`, `piece`, or {@see UnitTargetConversion::PACK}
     * @param  string  $target  the target in that unit
     * @param  float  $value  the unrounded figure the target is decided on
     * @param  string  $price  the figure as a latch stores it and an alert prints it: four decimals per unit, the shop's price for a `pack` target
     */
    public function __construct(
        public string $unit,
        public string $target,
        public Shop $shop,
        public float $value,
        public string $price,
    ) {}
}
