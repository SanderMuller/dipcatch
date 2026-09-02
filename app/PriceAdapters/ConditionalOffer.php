<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Carbon\CarbonImmutable;

/**
 * A price the shop advertises that not every shopper can pay: a personal
 * offer, a membership discount, a loyalty-card price.
 *
 * It is reported beside the price rather than as the price. Albert Heijn
 * shows "Bonus Box 15% korting — 2.97" on a product whose own API states
 * 3.49 and `isBonus: false` (verified 2026-09-02): 3.49 is what a shopper
 * without that offer pays, so 3.49 is what gets tracked and alerted on.
 */
final readonly class ConditionalOffer
{
    public function __construct(
        public string $price,
        public string $label,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
    ) {}

    /** Whether the offer's window is open now. An undated offer counts as open. */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->startsAt !== null && $now->lessThan($this->startsAt)) {
            return false;
        }

        return $this->endsAt === null || ! $now->greaterThan($this->endsAt);
    }
}
