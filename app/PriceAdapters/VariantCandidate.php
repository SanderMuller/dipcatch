<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * One option in a multi-variant product page (e.g. bol.com pack-size, brekz
 * radio selector). Surfaced when the adapter cannot pick a unique variant
 * from the URL alone — the user resolves it via the AddShop variant chooser
 * and the resulting `key` is persisted on the Shop row.
 */
final readonly class VariantCandidate
{
    public function __construct(
        public string $key,        // productID / sku / variant url
        public string $title,
        public string $price,      // decimal string
        public string $currency,   // ISO 4217 uppercase
        public bool $inStock = true,
    ) {}
}
