<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use App\Services\ShopDiscovery\WebShopDiscovery;

final readonly class UpdateProductDetails
{
    public function __construct(private WebShopDiscovery $discovery) {}

    public function __invoke(Product $product, string $title, ?string $imageUrl): void
    {
        self::fill($product, $title, $imageUrl);
        $product->save();

        // A new title changes what the web suggestions were checked against.
        $this->discovery->requeueIfStale($product);
    }

    /**
     * For a caller that saves other fields in the same write; it then calls
     * WebShopDiscovery::requeueIfStale() itself.
     */
    public static function fill(Product $product, string $title, ?string $imageUrl): void
    {
        // Jev read the old name; a renamed product may be another purchase.
        if (trim($title) !== $product->title) {
            $product->forceFill(['tracking_idea' => null]);
        }

        $product->forceFill([
            'title' => trim($title),
            'image_url' => $imageUrl,
        ]);
    }

    /**
     * Distinct images the shops reported, in the order the shops were added,
     * keyed by URL with the shop's host.
     *
     * @return array<string, string>
     */
    public static function shopImages(Product $product): array
    {
        $images = [];

        foreach ($product->shops->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $shop) {
            $url = $shop->safeImageUrl();

            if ($url !== null && ! isset($images[$url])) {
                $images[$url] = $shop->host ?? '';
            }
        }

        return $images;
    }
}
