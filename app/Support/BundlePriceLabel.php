<?php declare(strict_types=1);

namespace App\Support;

use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class BundlePriceLabel
{
    public static function condition(BundleOffer $offer, string $currency): string
    {
        return __(':quantity for :total', [
            'quantity' => $offer->quantity,
            'total' => MoneyFormatter::format($offer->totalPrice, $currency),
        ]);
    }

    public static function forShop(?Shop $shop): ?string
    {
        $offer = $shop?->liveBundleOffer();
        $singleItemPrice = $shop?->singleItemPrice();

        if ($shop === null || $offer === null || $singleItemPrice === null) {
            return null;
        }

        return self::withPromotion($offer, $shop->currency, $singleItemPrice, $shop->promotionWindow());
    }

    public static function forHistory(ProductCheapestHistory $segment, string $currency): ?string
    {
        $offer = $segment->bundleOffer();
        $singleItemPrice = $segment->singleItemPrice();

        if ($offer === null || $singleItemPrice === null) {
            return null;
        }

        return self::withPromotion($offer, $currency, $singleItemPrice);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function forSnapshot(array $snapshot): ?string
    {
        $quantity = $snapshot['bundle_quantity'] ?? null;
        $total = $snapshot['bundle_total_price'] ?? null;
        $single = $snapshot['single_item_price'] ?? null;
        $currency = $snapshot['currency'] ?? null;

        if (! is_int($quantity) || ! is_string($total) || ! is_string($single) || ! is_string($currency)) {
            return null;
        }

        try {
            $offer = new BundleOffer($quantity, $total);
        } catch (InvalidArgumentException) {
            return null;
        }

        $window = self::promotionWindow($snapshot);
        $hasPromotionDate = isset($snapshot['promotion_starts_at']) || isset($snapshot['promotion_ends_at']);

        if (! $offer->isCheaperThan($single) || ($hasPromotionDate && $window === null)) {
            return null;
        }

        return self::withPromotion($offer, $currency, $single, $window);
    }

    private static function withPromotion(
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function promotionWindow(array $snapshot): ?PromotionWindow
    {
        $endsAt = $snapshot['promotion_ends_at'] ?? null;

        if (! is_string($endsAt) || $endsAt === '') {
            return null;
        }

        $startsAt = $snapshot['promotion_starts_at'] ?? null;
        $label = $snapshot['promotion_label'] ?? null;

        try {
            return PromotionWindow::make(
                endsAt: CarbonImmutable::parse($endsAt),
                startsAt: is_string($startsAt) && $startsAt !== '' ? CarbonImmutable::parse($startsAt) : null,
                label: is_string($label) ? $label : null,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
