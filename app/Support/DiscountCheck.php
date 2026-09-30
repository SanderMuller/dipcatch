<?php declare(strict_types=1);

namespace App\Support;

/**
 * A shop's claimed regular price beside the lowest price DipCatch read there
 * in the 30 days before the discount. See {@see PriceBeforeDiscount}.
 */
final readonly class DiscountCheck
{
    public function __construct(
        public string $claimedRegularPrice,
        public string $lowestBefore,
        public string $currency,
    ) {}
}
