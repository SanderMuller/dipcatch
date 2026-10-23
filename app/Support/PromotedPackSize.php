<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use App\PriceAdapters\ShopSnapshot;

/**
 * A size a read states only second, as the primary of a shop that would
 * otherwise have none: AH's net content with no sales unit. Only a size the
 * source states, never a count read from the title. The price check, the
 * add-shop draft and the previews all lead with it, so they agree.
 */
final readonly class PromotedPackSize
{
    /** For a read that leaves the shop with no size: none of its own, and no stored one it keeps. */
    public static function for(Shop $shop, ?PackSize $packSize, ShopSnapshot $snapshot): ?PackSize
    {
        $keepsOne = $packSize !== null || (! $snapshot->packSizeAuthoritative && $shop->packSize() !== null);

        return $keepsOne ? null : self::first($snapshot->altPackSizes);
    }

    /**
     * @param  list<string>  $statedSizes
     */
    public static function first(array $statedSizes): ?PackSize
    {
        return array_find(array_map(PackSize::parse(...), $statedSizes), fn (?PackSize $size): bool => $size !== null);
    }

    /**
     * The second sizes a serialised snapshot states, as the probe and the
     * add-shop preview carry them.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    public static function statedIn(array $snapshot): array
    {
        return is_array($snapshot['alt_pack_sizes'] ?? null)
            ? array_values(array_filter($snapshot['alt_pack_sizes'], is_string(...)))
            : [];
    }
}
