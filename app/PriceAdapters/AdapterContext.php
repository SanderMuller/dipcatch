<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Optional per-call adapter input: user-supplied CSS selectors + fallback
 * currency. Consumed by {@see UserSelectorAdapter}; ignored by the others.
 */
final readonly class AdapterContext
{
    /**
     * @param  array{price?: ?string, title?: ?string, image?: ?string}  $selectors
     * @param  ?string  $variantKey  Identifier (productID / sku / variant URL)
     *                               of a previously-chosen variant inside a
     *                               ProductGroup. See {@see JsonLdAdapter}.
     */
    public function __construct(
        public array $selectors = [],
        public ?string $fallbackCurrency = null,
        public ?string $variantKey = null,
    ) {}

    public function hasPriceSelector(): bool
    {
        $price = $this->selectors['price'] ?? null;

        return is_string($price) && $price !== '';
    }
}
