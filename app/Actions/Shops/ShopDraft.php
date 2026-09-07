<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\PriceAdapters\ShopSnapshot;
use App\Support\ImageUrl;
use App\Support\PackSize;

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
        public bool $inStock,
        public ?string $priceSelector = null,
        public ?string $titleSelector = null,
        public ?string $imageSelector = null,
        public ?string $imageUrl = null,
        public ?string $gtin = null,
        public ?string $variantKey = null,
        public ?PackSize $packSize = null,
        public ?string $title = null,
    ) {}

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
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'pack_size' => $snapshot->packSize,
            'pack_size_authoritative' => $snapshot->packSizeAuthoritative,
        ];
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
        return new self(
            url: $url,
            adapterKey: $adapterKey,
            price: self::string($snapshot, 'price') ?? '',
            currency: self::string($snapshot, 'currency') ?? '',
            inStock: (bool) ($snapshot['in_stock'] ?? true),
            priceSelector: $priceSelector,
            titleSelector: $titleSelector,
            imageSelector: $imageSelector,
            imageUrl: self::string($snapshot, 'image_url'),
            gtin: self::string($snapshot, 'gtin'),
            variantKey: $variantKey,
            title: self::string($snapshot, 'title'),
            packSize: PackSize::resolve(
                self::string($snapshot, 'pack_size'),
                (bool) ($snapshot['pack_size_authoritative'] ?? false),
                self::string($snapshot, 'title'),
            ),
        );
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
