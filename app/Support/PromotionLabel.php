<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use Carbon\CarbonImmutable;

/**
 * Puts a shop's promotion window into words.
 *
 * Two lengths, because two surfaces need different things: a shop row has
 * space for the shop's own wording ("VOOR 1.69 until 6 Sep"), while a
 * product list already spends its line on the host and only has room for
 * the deadline ("until 6 Sep").
 *
 * Dates are rendered in Europe/Amsterdam. The stored instant is UTC, and a
 * window starting 8 September is stored at 22:00 on the 7th — printed
 * without converting it reads a day early.
 */
final readonly class PromotionLabel
{
    /** The shop's own wording plus the deadline, or null when it states no window. */
    public static function long(?Shop $shop): ?string
    {
        $window = $shop?->promotionWindow();

        if ($window === null) {
            return null;
        }

        return ($window->label ?? 'Bonus') . ' ' . self::deadline($shop);
    }

    /**
     * The shop that offers a price, and how long it lasts — "lidl.nl ·
     * until 6 Sep". Just the host when the shop states no window, so a
     * permanent price reads as one.
     */
    public static function withHost(?Shop $shop): ?string
    {
        if ($shop === null) {
            return null;
        }

        return implode(' · ', array_filter([$shop->host, self::short($shop)]));
    }

    /** The deadline alone: "until 6 Sep", "from 8 Sep", "ended 6 Sep". */
    public static function short(?Shop $shop): ?string
    {
        return $shop?->promotionWindow() === null ? null : self::deadline($shop);
    }

    private static function deadline(Shop $shop): string
    {
        $window = $shop->promotionWindow();

        if ($window === null) {
            return '';
        }

        if ($window->hasNotStarted()) {
            return 'from ' . self::shortDate($window->startsAt);
        }

        return $window->hasEnded()
            ? 'ended ' . self::shortDate($window->endsAt)
            : 'until ' . self::shortDate($window->endsAt);
    }

    private static function shortDate(?CarbonImmutable $moment): string
    {
        return $moment === null ? '' : $moment->setTimezone(DutchDate::ZONE)->format('j M');
    }
}
