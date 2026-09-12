<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
use App\Support\ImageUrl;
use App\Support\PackSize;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Everything needed to write one shop row, resolved and flat.
 *
 * Not a `ProbeOutcome`: Livewire flattens the outcome into an array on the
 * request that renders the preview, and `confirm()` runs on a later one where
 * the object no longer exists. Half of what gets written is form state anyway
 * — the three selectors and the chosen variant. This is the shape both the
 * web and an MCP tool can hand to {@see AttachShop}.
 */
final readonly class ShopDraft
{
    public function __construct(
        public string $url,
        public string $adapterKey,
        public string $price,
        public string $currency,
        /** True in stock, false out of stock, null when the page did not say. */
        public ?bool $inStock,
        public ?string $priceSelector = null,
        public ?string $titleSelector = null,
        public ?string $imageSelector = null,
        public ?string $imageUrl = null,
        public ?string $gtin = null,
        public ?string $variantKey = null,
        public ?PackSize $packSize = null,
        public ?string $title = null,
        public ?string $singleItemPrice = null,
        public ?BundleOffer $bundleOffer = null,
        public ?PromotionWindow $promotionWindow = null,
    ) {}

    public function trackedPrice(): string
    {
        $singleItemPrice = $this->singleItemPrice ?? $this->price;

        if ($this->bundleOffer === null
            || ! $this->bundleOffer->isCheaperThan($singleItemPrice)
            || ($this->promotionWindow !== null && ! $this->promotionWindow->isRunning())) {
            return $singleItemPrice;
        }

        return $this->bundleOffer->effectiveUnitPrice();
    }

    /**
     * Flattens a successful probe into the preview shape both the Livewire
     * components and the MCP tools carry between the preview and the write.
     *
     * @return array<string, mixed>
     */
    public static function flatten(ProbeOutcome $outcome): array
    {
        $snapshot = $outcome->snapshot;

        if (! $snapshot instanceof ShopSnapshot) {
            return [];
        }

        return [
            'title' => $snapshot->title,
            'image_url' => ImageUrl::absolute($snapshot->imageUrl, $outcome->normalizedUrl ?? ''),
            'gtin' => $snapshot->gtin,
            'price' => $snapshot->trackedPrice(),
            'single_item_price' => $snapshot->price,
            'bundle_quantity' => $snapshot->bundleOffer?->quantity,
            'bundle_total_price' => $snapshot->bundleOffer?->totalPrice,
            'promotion_starts_at' => $snapshot->promotionWindow?->startsAt?->toIso8601String(),
            'promotion_ends_at' => $snapshot->promotionWindow?->endsAt->toIso8601String(),
            'promotion_label' => $snapshot->promotionWindow?->label,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'stock_signal' => $snapshot->stockSignal,
            'pack_size' => $snapshot->packSize,
            'pack_size_authoritative' => $snapshot->packSizeAuthoritative,
        ];
    }

    /**
     * A preview snapshot states stock as true, false, or null for unknown —
     * a missing key is unknown too, never "available".
     */
    private static function stock(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Builds a draft from a flattened preview snapshot plus the form state
     * around it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(
        array $snapshot,
        string $url,
        string $adapterKey,
        ?string $priceSelector = null,
        ?string $titleSelector = null,
        ?string $imageSelector = null,
        ?string $variantKey = null,
    ): self {
        $singleItemPrice = self::string($snapshot, 'single_item_price') ?? self::string($snapshot, 'price') ?? '';
        $bundleOffer = self::bundleOffer($snapshot);
        $promotionWindow = self::promotionWindow($snapshot);
        $hasPromotionDate = self::string($snapshot, 'promotion_starts_at') !== null
            || self::string($snapshot, 'promotion_ends_at') !== null;

        if ($bundleOffer !== null
            && (! $bundleOffer->isCheaperThan($singleItemPrice) || ($hasPromotionDate && $promotionWindow === null))) {
            $bundleOffer = null;
        }

        return new self(
            url: $url,
            adapterKey: $adapterKey,
            price: self::string($snapshot, 'price') ?? '',
            currency: self::string($snapshot, 'currency') ?? '',
            inStock: self::stock($snapshot['in_stock'] ?? null),
            priceSelector: $priceSelector,
            titleSelector: $titleSelector,
            imageSelector: $imageSelector,
            imageUrl: self::string($snapshot, 'image_url'),
            gtin: self::string($snapshot, 'gtin'),
            variantKey: $variantKey,
            packSize: PackSize::resolve(
                self::string($snapshot, 'pack_size'),
                (bool) ($snapshot['pack_size_authoritative'] ?? false),
                self::string($snapshot, 'title'),
            ),
            title: self::string($snapshot, 'title'),
            singleItemPrice: $singleItemPrice,
            bundleOffer: $bundleOffer,
            promotionWindow: $promotionWindow,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function bundleOffer(array $snapshot): ?BundleOffer
    {
        $quantity = $snapshot['bundle_quantity'] ?? null;
        $total = self::string($snapshot, 'bundle_total_price');

        if (! is_int($quantity) || $total === null) {
            return null;
        }

        try {
            return new BundleOffer($quantity, $total);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function promotionWindow(array $snapshot): ?PromotionWindow
    {
        $endsAt = self::string($snapshot, 'promotion_ends_at');

        if ($endsAt === null) {
            return null;
        }

        try {
            return PromotionWindow::make(
                endsAt: CarbonImmutable::parse($endsAt),
                startsAt: ($startsAt = self::string($snapshot, 'promotion_starts_at')) === null
                    ? null
                    : CarbonImmutable::parse($startsAt),
                label: self::string($snapshot, 'promotion_label'),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function string(array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
