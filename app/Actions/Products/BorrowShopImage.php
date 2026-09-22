<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Gives a product with no image the image of one of its shops. An image the
 * product already has, whether a shop's or set by hand, stays.
 *
 * The check reads the product row under a lock, not the caller's copy: a
 * request that set an image after the caller loaded the product must not be
 * overwritten.
 */
final readonly class BorrowShopImage
{
    /**
     * @return bool whether the product took the shop's image
     */
    public function __invoke(Product $product, Shop $shop): bool
    {
        $image = $shop->safeImageUrl();

        if ($image === null) {
            return false;
        }

        $borrowed = DB::transaction(function () use ($product, $image): bool {
            $locked = Product::query()->lockForUpdate()->find($product->getKey());

            if ($locked === null || $locked->safeImageUrl() !== null) {
                return false;
            }

            $locked->forceFill(['image_url' => $image])->save();

            return true;
        });

        if ($borrowed) {
            $product->setAttribute('image_url', $image);
            $product->syncOriginalAttribute('image_url');
        }

        return $borrowed;
    }
}
