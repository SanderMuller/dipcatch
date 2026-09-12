<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Support\DutchDate;
use Carbon\CarbonImmutable;

/**
 * The promotion period a schema.org offer states.
 *
 * `priceValidUntil` is used two ways in the wild. spar.nl states
 * `2026-09-03` and zooplus `2026-09-09`, both real weekly promotions; but
 * pharmacy4pets states `2027-12-31`, a placeholder meaning "no promotion",
 * which would otherwise render as a discount running for another year.
 *
 * Nothing in the markup separates the two, so a date beyond
 * {@see MAX_DAYS_AHEAD} is treated as the placeholder it almost certainly
 * is. The rule has a known expiry: the pharmacy4pets date enters the window
 * around 2 October 2027 — see specs/promotion-window.md.
 */
final readonly class OfferValidity
{
    /** Dutch retail promotions run in weeks; this is generous. */
    public const int MAX_DAYS_AHEAD = 90;

    /**
     * @param  array<string, mixed>  $offer
     */
    public static function windowFrom(array $offer, ?CarbonImmutable $now = null): ?PromotionWindow
    {
        $endsAt = DutchDate::endOfDay($offer['priceValidUntil'] ?? null);

        if ($endsAt === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        if ($endsAt->greaterThan($now->addDays(self::MAX_DAYS_AHEAD))) {
            return null;
        }

        return PromotionWindow::make(
            endsAt: $endsAt,
            startsAt: DutchDate::startOfDay($offer['validFrom'] ?? null),
        );
    }
}
