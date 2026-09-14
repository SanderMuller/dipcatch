<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Shop;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;

final readonly class ResolvedBundlePricing
{
    /**
     * @param  ?BundleOffer  $appliedOffer  The offer the tracked price was taken from,
     *                                      which is the offer the price check records.
     *                                      Null when the tracked price is the single-item one.
     * @param  array<string, mixed>  $promotionUpdates
     */
    private function __construct(
        public ?string $trackedPrice,
        public ?string $singleItemPrice,
        public ?BundleOffer $offer,
        public ?BundleOffer $appliedOffer,
        private array $promotionUpdates,
    ) {}

    public static function merge(
        Shop $shop,
        ?string $singleItemPrice,
        ?BundleOffer $offer,
        bool $offerAuthoritative,
        ?PromotionWindow $reportedWindow,
        bool $windowAuthoritative,
    ): self {
        $storedOffer = $shop->bundleOffer();
        $storedWindow = $shop->promotionWindow();
        $preservesStoredBundle = ! $offerAuthoritative
            && $storedOffer !== null
            && $storedWindow?->hasEnded() !== true;

        if ($preservesStoredBundle) {
            // The decision was made on an earlier check and survives only in
            // the stored columns, so this is the one path that has to read it
            // back rather than make it.
            $trackedPrice = is_string($shop->current_price) ? $shop->current_price : null;

            return new self(
                trackedPrice: $trackedPrice,
                singleItemPrice: $shop->singleItemPrice(),
                offer: $storedOffer,
                appliedOffer: $storedOffer->isTrackedAt($trackedPrice) ? $storedOffer : null,
                promotionUpdates: [],
            );
        }

        $offer = $offerAuthoritative ? $offer : null;

        if ($offer !== null && (! is_string($singleItemPrice) || ! $offer->isCheaperThan($singleItemPrice))) {
            $offer = null;
        }

        $window = $windowAuthoritative ? $reportedWindow : $storedWindow;
        $trackedPrice = $singleItemPrice;
        $appliedOffer = null;

        if ($window?->hasEnded() === true) {
            $offer = null;
        }

        // The offer has already been gated against the single-item price
        // above, so reaching here with one means it beats that price.
        if ($offer !== null && ($window === null || $window->isRunning())) {
            $trackedPrice = $offer->effectiveUnitPrice();
            $appliedOffer = $offer;
        }

        return new self(
            trackedPrice: $trackedPrice,
            singleItemPrice: $singleItemPrice,
            offer: $offer,
            appliedOffer: $appliedOffer,
            promotionUpdates: self::promotionUpdatesFor($reportedWindow, $windowAuthoritative),
        );
    }

    /**
     * A source that reads promotion fields and finds none ends the promotion
     * on screen; one that does not read them at all leaves it alone.
     *
     * @return array<string, mixed>
     */
    private static function promotionUpdatesFor(?PromotionWindow $window, bool $authoritative): array
    {
        if ($window === null && ! $authoritative) {
            return [];
        }

        return [
            'promotion_starts_at' => $window?->startsAt?->utc(),
            'promotion_ends_at' => $window?->endsAt->utc(),
            'promotion_label' => $window?->label,
        ];
    }

    /** @return array<string, int|string|null> */
    public function shopUpdates(): array
    {
        return [
            'current_price' => $this->trackedPrice,
            'single_item_price' => $this->singleItemPrice,
            'bundle_quantity' => $this->offer?->quantity,
            'bundle_total_price' => $this->offer?->totalPrice,
        ];
    }

    /** @return array<string, mixed> */
    public function promotionUpdates(): array
    {
        return $this->promotionUpdates;
    }

    /** @return array{price: ?string, single_item_price: ?string, bundle_quantity: ?int, bundle_total_price: ?string} */
    public function priceCheckAttributes(bool $successful, ?string $reportedPrice): array
    {
        if (! $successful) {
            return [
                'price' => $reportedPrice,
                'single_item_price' => null,
                'bundle_quantity' => null,
                'bundle_total_price' => null,
            ];
        }

        return [
            'price' => $this->trackedPrice,
            'single_item_price' => $this->singleItemPrice,
            'bundle_quantity' => $this->appliedOffer?->quantity,
            'bundle_total_price' => $this->appliedOffer?->totalPrice,
        ];
    }

    /** @return array<string, int|string|null> */
    public static function expiredFailureUpdates(Shop $shop): array
    {
        if ($shop->bundleOffer() === null || $shop->promotionWindow()?->hasEnded() !== true) {
            return [];
        }

        return [
            'current_price' => $shop->singleItemPrice(),
            'bundle_quantity' => null,
            'bundle_total_price' => null,
        ];
    }
}
