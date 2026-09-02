<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Successful adapter extraction. Prices are decimal strings (compatible with
 * `bccomp` + the existing `PriceCheck.price` decimal(12,2) column) — no cent
 * integers anywhere.
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
        public bool $inStock,
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
    ) {}
}
