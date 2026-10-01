<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\VariantCandidate;
use App\Support\PackSize;
use Closure;

/**
 * Answers a shop's variant question from the product it is added to. A page
 * like brekz.nl names no variant in its URL, so every link asked which pack
 * to track, although the product already said: the one size its shops sell.
 */
final readonly class MatchingPackVariant
{
    /**
     * A page that names no variant, added to a product that already says which
     * pack it is: the question answered rather than asked, as the key picked
     * and the page read again for it. Null when nobody asked the question, the
     * caller already chose, or no single variant fits.
     *
     * `$preferred` asks for that size instead of the product's own.
     *
     * @param  Closure(string): ExtractionResult  $readFor
     * @return array{0: string, 1: ExtractionResult}|null
     */
    public static function answer(?Product $product, ExtractionResult $extraction, ?string $chosen, Closure $readFor, ?PackSize $preferred = null): ?array
    {
        $key = $chosen === null && $product instanceof Product && $extraction->isAmbiguous()
            ? self::keyFor($product, $extraction->variants, $preferred)
            : null;

        if ($key === null) {
            return null;
        }

        // A page that will not price the picked variant keeps its question.
        $read = $readFor($key);

        return $read->isSuccess() ? [$key, $read] : null;
    }

    /**
     * The key of the one variant whose name states `$preferred`, else the
     * product's pack size. Null when there is no single size to match or no
     * single variant has it.
     *
     * @param  list<VariantCandidate>  $variants
     */
    public static function keyFor(Product $product, array $variants, ?PackSize $preferred = null): ?string
    {
        $size = $preferred ?? self::productSize($product);

        if (! $size instanceof PackSize) {
            return null;
        }

        $matches = array_values(array_filter(
            $variants,
            static fn (VariantCandidate $variant): bool => $size->isSameSizeAs(PackSize::resolve(packSize: null, authoritative: false, title: $variant->title)),
        ));

        return count($matches) === 1 ? $matches[0]->key : null;
    }

    /**
     * The one size every shop on the product states, the rule `add_shop`
     * warns on too. With no shop stating one, the product's own title.
     */
    private static function productSize(Product $product): ?PackSize
    {
        $sizes = $product->shops
            ->map(static fn (Shop $shop): ?PackSize => $shop->pack_quantity === null || ! is_string($shop->pack_unit)
                ? null
                : PackSize::of((float) $shop->pack_quantity, $shop->pack_unit))
            ->filter();

        // Read like the variants' names, so "12st Bar 55g" is 660 g on both sides.
        if ($sizes->isEmpty()) {
            return PackSize::resolve(packSize: null, authoritative: false, title: $product->title);
        }

        $first = $sizes->first();

        return $sizes->every(static fn (PackSize $size): bool => $size->isSameSizeAs($first)) ? $first : null;
    }
}
