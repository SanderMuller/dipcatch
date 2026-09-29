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
     * @param  bool  $acceptPageDefault  A recheck of a shop saved before its
     *                                   reader could see variants: read the
     *                                   variant the page defaults to, as it
     *                                   always has, rather than fail the check.
     */
    public function __construct(
        public array $selectors = [],
        public ?string $fallbackCurrency = null,
        public ?string $variantKey = null,
        public bool $acceptPageDefault = false,
    ) {}

    public function hasPriceSelector(): bool
    {
        $price = $this->selectors['price'] ?? null;

        return is_string($price) && $price !== '';
    }

    public function withVariantKey(string $variantKey): self
    {
        return new self(
            selectors: $this->selectors,
            fallbackCurrency: $this->fallbackCurrency,
            variantKey: $variantKey,
            acceptPageDefault: $this->acceptPageDefault,
        );
    }
}
