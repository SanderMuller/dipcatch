<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use App\PriceAdapters\PromotionWindow;
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

        return self::forWindow($window);
    }

    public static function forWindow(PromotionWindow $window): string
    {
        return ($window->label ?? 'Bonus') . ' ' . self::deadline($window);
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

    /**
     * The deal a shop runs now, in the shop's words: "2 for €4.00 · or €2.85
     * each", "Bonus until 27 Sep". Null when nothing is running — an announced
     * or ended window is not a discount anyone can have today.
     */
    public static function runningDeal(?Shop $shop): ?string
    {
        $window = $shop?->promotionWindow();
        $running = $window !== null && $window->isRunning() ? self::forWindow($window) : null;
        $offer = $shop?->liveBundleOffer();
        $single = $shop?->singleItemPrice();

        if ($shop === null || $offer === null || $single === null || ! $offer->isCheaperThan($single)) {
            return $running;
        }

        // The bundle terms, with the window only while it runs: a bundle read
        // beside a window that has ended must not say "ended" as today's deal.
        return implode(' · ', array_filter([
            $running,
            BundlePriceLabel::condition($offer, $shop->currency),
            __('or :price each', ['price' => MoneyFormatter::format($single, $shop->currency)]),
        ]));
    }

    /** The deadline alone: "until 6 Sep", "from 8 Sep", "ended 6 Sep". */
    public static function short(?Shop $shop): ?string
    {
        $window = $shop?->promotionWindow();

        return $window === null ? null : self::deadline($window);
    }

    private static function deadline(PromotionWindow $window): string
    {
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
