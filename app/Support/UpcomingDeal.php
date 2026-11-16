<?php declare(strict_types=1);

namespace App\Support;

use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;
use Carbon\CarbonImmutable;

/**
 * An offer a shop has announced but not started: Aldi's next campaign
 * price, Dirk's and DekaMarkt's next-week offers pages, Jumbo's promotion
 * for next Wednesday. Every reader hands its offer here, so one rule decides
 * whether it is a deal at all and what it is called.
 *
 * The tracked price stays today's until the offer starts; this only builds
 * the window the "Upcoming deal" block shows.
 */
final readonly class UpcomingDeal
{
    /**
     * The window for an announced offer, or null when it is not one: it has
     * started already, or it is no cheaper than today and states no
     * multi-buy or percentage that makes it a deal at any price.
     *
     * @param  ?string  $offerPrice  The price the offer charges for one item, when the shop states it.
     * @param  ?string  $text  The shop's own wording for the offer.
     * @param  bool  $priceDecides  Only a lower price makes a deal; the wording still names it (Aldi, whose offer price is what a shopper pays).
     */
    public static function window(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $offerPrice,
        ?string $text,
        string $todayPrice,
        bool $priceDecides = false,
        ?CarbonImmutable $now = null,
    ): ?PromotionWindow {
        if (! $startsAt->greaterThan($now ?? CarbonImmutable::now())) {
            return null;
        }

        $cheaper = is_numeric($offerPrice) && is_numeric($todayPrice)
            && bccomp(Numeric::str($offerPrice), Numeric::str($todayPrice), 2) < 0;

        if (! $cheaper && ($priceDecides || ! self::isDealAtAnyPrice($text))) {
            return null;
        }

        return PromotionWindow::make(endsAt: $endsAt, startsAt: $startsAt, label: self::label($text, $offerPrice));
    }

    /**
     * The label to store: the shop's wording, or the offer price when the
     * wording is only a price ("voor 1,99", "per stuk 1,99", "500 gram 0,79"),
     * is missing, or names no deal ("OP=OP").
     * A price is stored as a bare amount, which {@see PromotionLabel} formats
     * in the reader's own money format when it shows it.
     */
    public static function label(?string $text, ?string $offerPrice): ?string
    {
        $text = $text === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $text));
        $price = is_numeric($offerPrice) ? Numeric::trimmed(Numeric::str($offerPrice)) : null;

        if ($text !== '' && ! self::isOnlyPrice($text) && ! self::namesNoDeal($text)) {
            return $text;
        }

        return $price ?? ($text === '' || self::namesNoDeal($text) ? null : $text);
    }

    /** "1+1 GRATIS", "2 voor 2,99", "2e halve PRIJS", "25% korting", "-26%". */
    private static function isDealAtAnyPrice(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return preg_match('/\d\s*%/u', $text) === 1
            || BundleOffer::fromLabel($text, '1000.00') !== null;
    }

    private static function isOnlyPrice(string $text): bool
    {
        $size = '\d+(?:[.,]\d+)?\s*(?:gram|gr|g|kilo|kg|liter|l|ml|cl)\.?';

        return preg_match('/^(?:voor|per\s+\p{L}+|' . $size . ')?\s*€?\s*\d+[.,]\d{2}$/iu', $text) === 1;
    }

    private static function namesNoDeal(string $text): bool
    {
        return preg_match('/^op\s*=\s*op$/iu', $text) === 1;
    }
}
