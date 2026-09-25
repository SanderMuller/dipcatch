<?php declare(strict_types=1);

namespace App\Support;

use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;

final readonly class BundlePriceLabel
{
    public static function condition(BundleOffer $offer, string $currency): string
    {
        return __(':quantity for :total', [
            'quantity' => $offer->quantity,
            'total' => MoneyFormatter::format($offer->totalPrice, $currency),
        ]);
    }

    /**
     * The bundle clause a notification body appends to its price sentence,
     * or an empty string when there is no offer to state.
     */
    public static function suffix(?BundleOffer $offer, string $currency): string
    {
        return $offer === null ? '' : ' · ' . self::condition($offer, $currency);
    }

    public static function forShop(?Shop $shop): ?string
    {
        $offer = $shop?->liveBundleOffer();
        $singleItemPrice = $shop?->singleItemPrice();

        if ($shop === null || $offer === null || $singleItemPrice === null) {
            return null;
        }

        return self::forTerms($offer, $shop->currency, $singleItemPrice, $shop->promotionWindow());
    }

    public static function forHistory(ProductCheapestHistory $segment, string $currency): ?string
    {
        $offer = $segment->bundleOffer();
        $singleItemPrice = $segment->singleItemPrice();

        if ($offer === null || $singleItemPrice === null) {
            return null;
        }

        return self::forTerms($offer, $currency, $singleItemPrice);
    }

    /**
     * The bundle line of a stored alert. An alert payload carries the bundle
     * and the single-item price it was written under, never a promotion
     * window.
     *
     * @param  array<string, mixed>  $data
     */
    public static function forAlert(array $data): ?string
    {
        $single = $data['single_item_price'] ?? null;
        $currency = $data['currency'] ?? null;

        if (! is_string($single) || ! is_string($currency)) {
            return null;
        }

        $offer = BundleOffer::stored($data['bundle_quantity'] ?? null, $data['bundle_total_price'] ?? null, $single);

        return $offer === null ? null : self::forTerms($offer, $currency, $single);
    }

    public static function forTerms(
        BundleOffer $offer,
        string $currency,
        string $singleItemPrice,
        ?PromotionWindow $window = null,
    ): string {
        return implode(' · ', array_filter([
            $window === null ? null : PromotionLabel::forWindow($window),
            self::condition($offer, $currency),
            __('or :price each', ['price' => MoneyFormatter::format($singleItemPrice, $currency)]),
        ]));
    }
}
