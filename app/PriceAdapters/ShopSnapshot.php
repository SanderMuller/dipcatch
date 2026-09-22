<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Enums\ConsumerPriceIssue;

/**
 * Successful adapter extraction. Prices are decimal strings (compatible with
 * `bccomp` + the existing `PriceCheck.price` decimal(12,2) column) — no cent
 * integers anywhere.
 *
 * A host adapter augments a snapshot through the `with*` copy methods, one
 * per concept. Calling one is the claim of authority over that concept, so
 * passing null clears the inherited value; an adapter that does not read
 * promotion windows never calls `withPromotionWindow()`, and the inherited
 * window survives. `withoutPromotionWindowAuthority()` is the one exception,
 * for an adapter that must withdraw a claim it knows to be empty.
 */
final readonly class ShopSnapshot
{
    /**
     * @param  array<string, mixed>  $raw  Adapter-specific debug payload.
     */
    public function __construct(
        public string $title,
        public ?string $imageUrl,
        public string $price,        // e.g. "289.00"
        public string $currency,     // ISO 4217 uppercase, e.g. "EUR"
        /**
         * True in stock, false out of stock, null when the page did not say
         * — never a guess. See {@see StockAvailability} and {@see StockText}.
         */
        public ?bool $inStock,
        public array $raw = [],
        /** Raw pack-size text from the source, e.g. "200 g". */
        public ?string $packSize = null,
        /**
         * True when a structured source supplied the size field at all, even
         * empty — an authoritative empty size clears stored pack data, while
         * a non-authoritative snapshot allows the title fallback.
         */
        public bool $packSizeAuthoritative = false,
        /** Normalized GTIN (EAN/UPC) when the source published one. */
        public ?string $gtin = null,
        /**
         * True when the adapter reads GTIN fields at all. An authoritative
         * snapshot with a null GTIN clears the stored value — a page that
         * stopped publishing one must not keep raising a stale mismatch
         * warning; a source with no GTIN concept leaves it untouched.
         */
        public bool $gtinAuthoritative = false,
        /**
         * A price only some shoppers can pay — see {@see ConditionalOffer}.
         * Never the tracked price.
         */
        public ?ConditionalOffer $conditionalOffer = null,
        /**
         * True when the source reads conditional offers at all. An
         * authoritative snapshot without one clears the stored offer, so an
         * expired campaign stops being shown.
         */
        public bool $conditionalOfferAuthoritative = false,
        /** How long the shop says this price runs — see {@see PromotionWindow}. */
        public ?PromotionWindow $promotionWindow = null,
        /**
         * True when the source reads promotion windows at all. An
         * authoritative snapshot without one clears the stored window, so a
         * promotion that ended stops being shown.
         */
        public bool $promotionWindowAuthoritative = false,
        /** What the verdict was read from, e.g. `https://schema.org/InStock`. */
        public ?string $stockSignal = null,
        /** A public multi-buy offer that changes the tracked unit price. */
        public ?BundleOffer $bundleOffer = null,
        /** True when the source exposed its product-bound bundle field. */
        public bool $bundleOfferAuthoritative = false,
        /**
         * Why this number is not a price a shopper can pay, when it is not.
         * Read from the page as a whole by {@see VatStatement} and
         * {@see TradeGate}, not by any one adapter — a shop states these in
         * its own copy, and which adapter read the price is beside the point.
         */
        public ?ConsumerPriceIssue $consumerPriceIssue = null,
        /** The words the page used, so a surface can show the evidence. */
        public ?string $consumerPriceNote = null,
    ) {}

    public function trackedPrice(): string
    {
        if ($this->bundleOffer === null || ($this->promotionWindow !== null && ! $this->promotionWindow->isRunning())) {
            return $this->price;
        }

        $bundlePrice = $this->bundleOffer->effectiveUnitPrice();

        return $this->bundleOffer->isCheaperThan($this->price) ? $bundlePrice : $this->price;
    }

    public function withPackSize(string $packSize): self
    {
        return clone($this, ['packSize' => $packSize, 'packSizeAuthoritative' => true]);
    }

    public function withPromotionWindow(?PromotionWindow $promotionWindow): self
    {
        return clone($this, ['promotionWindow' => $promotionWindow, 'promotionWindowAuthoritative' => true]);
    }

    /**
     * Withdraw an inherited claim of promotion authority.
     *
     * A host adapter whose own promotion source is unavailable on this page
     * calls this when the snapshot it augments claims authority it does not
     * have. Without it the null window would clear a promotion that is still
     * running — see {@see Hosts\LidlAdapter}.
     */
    public function withoutPromotionWindowAuthority(): self
    {
        return clone($this, ['promotionWindowAuthoritative' => false]);
    }

    public function withBundleOffer(?BundleOffer $bundleOffer): self
    {
        return clone($this, ['bundleOffer' => $bundleOffer, 'bundleOfferAuthoritative' => true]);
    }

    public function withStock(bool $inStock, string $stockSignal): self
    {
        return clone($this, ['inStock' => $inStock, 'stockSignal' => $stockSignal]);
    }

    public function withConsumerPriceIssue(?ConsumerPriceIssue $issue, ?string $note): self
    {
        return clone($this, ['consumerPriceIssue' => $issue, 'consumerPriceNote' => $note]);
    }

    public function withCurrency(string $currency): self
    {
        return clone($this, ['currency' => $currency]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function withRaw(array $raw): self
    {
        return clone($this, ['raw' => $raw]);
    }
}
