<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Models\Shop;
use App\Support\PackSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves how much is in the pack the owner buys at one shop, for a page that
 * states no size: "660 g", "1 l", "4 pieces". It gives that shop a price per
 * unit and can make it the best value. An empty input removes it.
 *
 * The shop's own size wins once the page states one. The owner's size is
 * never passed to another shop: another shop can sell another pack.
 */
final readonly class SaveOwnerPackSize
{
    /**
     * A word for the pack itself says nothing about what is in it, and
     * `PackSize::parse()` reads "1 pack" as one piece.
     */
    private const string PACK_WORDS = '/\b(packs?|pak|pakken|pakje|doos|dozen|box|boxes|verpakking|verpakkingen|bag|zak)\b/iu';

    /**
     * @throws ValidationException on `$field` when the shop is not this product's, or the size cannot be read
     */
    public function __invoke(Product $product, string $shopId, ?string $input, string $field = 'packSize'): void
    {
        // Not a uuid is not a shop: PostgreSQL refuses it rather than finding none.
        $shop = Str::isUuid($shopId) ? $product->shops()->whereKey($shopId)->first() : null;

        if (! $shop instanceof Shop) {
            throw ValidationException::withMessages([$field => __('No such shop on this product.')]);
        }

        $input = trim((string) $input);
        $size = $input === '' ? null : PackSize::parse($input);

        // The column keeps decimal(10,2): a size it cannot hold is refused, not cut.
        $fits = $size !== null && $size->quantity >= 0.01 && $size->quantity <= 99999999.99;

        if ($input !== '' && (! $fits || preg_match(self::PACK_WORDS, $input) === 1)) {
            throw ValidationException::withMessages([$field => __('Write how much is in the pack, such as 660 g, 1 l or 4 pieces.')]);
        }

        DB::transaction(function () use ($product, $shop, $size): void {
            $shop->forceFill([
                'owner_pack_quantity' => $size?->quantity,
                'owner_pack_unit' => $size?->unit,
            ])->save();

            // A shop joining the comparison is not a price moving: without
            // `sizesChanged` the first price per unit reads as a fall.
            $product->refresh()->recomputeCheapestShop(sizesChanged: true);
            app(DetectUnitPriceTarget::class)($product->refresh());
        });
    }
}
