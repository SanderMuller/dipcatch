<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ConsumerPriceIssue;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
use App\Support\ImageUrl;
use App\Support\PackSize;
use App\Support\ProductTitle;
use Carbon\CarbonImmutable;
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
        /**
         * A title the caller supplied instead of the page's, carried from the
         * preview to the write. Separate from `$title` on purpose: the preview
         * shows what the page said, and the pack-size fallback parses that,
         * not a name a person chose.
         */
        public ?string $titleOverride = null,
        /** Why this price is not one a shopper can pay, when it is not. */
        public ?ConsumerPriceIssue $consumerPriceIssue = null,
        /** The words the page used to say so. */
        public ?string $consumerPriceNote = null,
    ) {}

    public function trackedPrice(): string
    {
        $singleItemPrice = $this->singleItemPrice ?? $this->price;

        return $this->bundleOffer?->appliesTo($singleItemPrice, $this->promotionWindow) === true
            ? $this->bundleOffer->effectiveUnitPrice()
            : $singleItemPrice;
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
            // Cleaned here rather than in each client: the web preview, the
            // MCP tools and anything written from this array all want the
            // product's name without the shop's search-engine tail.
            'title' => ProductTitle::clean($snapshot->title, $outcome->host),
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
            'consumer_price_issue' => $snapshot->consumerPriceIssue?->value,
            'consumer_price_note' => $snapshot->consumerPriceNote,
            'variants_on_page' => $snapshot->variantsOnPage,
            'variant_note' => $snapshot->variantNote(),
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
        ?string $titleOverride = null,
    ): self {
        $singleItemPrice = self::string($snapshot, 'single_item_price') ?? self::string($snapshot, 'price') ?? '';
        $bundleOffer = BundleOffer::stored(
            $snapshot['bundle_quantity'] ?? null,
            self::string($snapshot, 'bundle_total_price'),
            $singleItemPrice,
        );
        $promotionWindow = self::promotionWindow($snapshot);
        $hasPromotionDate = self::string($snapshot, 'promotion_starts_at') !== null
            || self::string($snapshot, 'promotion_ends_at') !== null;

        // A snapshot stating a promotion date this draft could not parse says
        // the bundle runs on terms it cannot check, so the bundle is dropped.
        if ($hasPromotionDate && $promotionWindow === null) {
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
            titleOverride: $titleOverride,
            consumerPriceIssue: ConsumerPriceIssue::tryFrom(self::string($snapshot, 'consumer_price_issue') ?? ''),
            consumerPriceNote: self::string($snapshot, 'consumer_price_note'),
        );
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
