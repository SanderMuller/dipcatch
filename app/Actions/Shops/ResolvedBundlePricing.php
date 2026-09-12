<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Shop;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;

final readonly class ResolvedBundlePricing
{
    private function __construct(
        public ?string $trackedPrice,
        public ?string $singleItemPrice,
        public ?BundleOffer $offer,
        private bool $preservesStoredBundle,
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
            return new self(
                trackedPrice: is_string($shop->current_price) ? $shop->current_price : null,
                singleItemPrice: $shop->singleItemPrice(),
                offer: $storedOffer,
                preservesStoredBundle: true,
            );
        }

        $offer = $offerAuthoritative ? $offer : null;

        if ($offer !== null && (! is_string($singleItemPrice) || ! $offer->isCheaperThan($singleItemPrice))) {
            $offer = null;
        }

        $window = $windowAuthoritative ? $reportedWindow : $storedWindow;
        $trackedPrice = $singleItemPrice;

        if ($window?->hasEnded() === true) {
            $offer = null;
        }

        if ($offer !== null
            && ($window === null || $window->isRunning())
            && is_string($singleItemPrice)
            && $offer->isCheaperThan($singleItemPrice)) {
            $trackedPrice = $offer->effectiveUnitPrice();
        }

        return new self($trackedPrice, $singleItemPrice, $offer, preservesStoredBundle: false);
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
    public function promotionUpdates(?PromotionWindow $window, bool $authoritative): array
    {
        if ($this->preservesStoredBundle || ($window === null && ! $authoritative)) {
            return [];
        }

        return [
            'promotion_starts_at' => $window?->startsAt?->utc(),
            'promotion_ends_at' => $window?->endsAt->utc(),
            'promotion_label' => $window?->label,
        ];
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
            'bundle_quantity' => $this->appliedOffer()?->quantity,
            'bundle_total_price' => $this->appliedOffer()?->totalPrice,
        ];
    }

    private function appliedOffer(): ?BundleOffer
    {
        if ($this->offer === null || $this->trackedPrice === null) {
            return null;
        }

        return $this->trackedPrice === $this->offer->effectiveUnitPrice() ? $this->offer : null;
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
