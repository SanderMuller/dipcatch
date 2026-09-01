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
    ) {}
}
