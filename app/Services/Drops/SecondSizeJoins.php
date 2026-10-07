<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * When each shop started competing on the second size it states, for
 * {@see ReferenceEpoch}: a shop that joins the comparison on 560 g beside its
 * 20 pieces was there all along, unseen, and its first win is not a price
 * that fell.
 *
 * Only a shop that competes on that size now — its own pack columns are in
 * another unit than the product compares in. A shop that competes on its pack
 * columns and merely gains an unused second size has joined nothing, so its
 * history, and a real drop against it, stand.
 */
final readonly class SecondSizeJoins
{
    /**
     * @param  array<string, CarbonImmutable>  $joined  joins already known, such as a size Jev confirmed; the later one wins
     * @return array<string, CarbonImmutable>
     */
    public static function of(Product $product, ?string $unit, array $joined = []): array
    {
        if ($unit === null) {
            return [];
        }

        $since = [];

        $shops = $product->shops()
            ->whereNotNull('alt_pack_since')
            ->where('alt_pack_unit', $unit)
            ->where(fn (Builder $query): Builder => $query->whereNull('pack_unit')->orWhere('pack_unit', '!=', $unit))
            // A second size Jev rejected is not one the shop competes on.
            ->where(fn (Builder $query): Builder => $query->whereNull('alt_pack_confirmed')->orWhere('alt_pack_confirmed', true))
            ->get(['id', 'alt_pack_since']);

        foreach ($shops as $shop) {
            $id = (string) $shop->id;
            $at = self::since($shop);

            if ($at !== null) {
                $since[$id] = isset($joined[$id]) && $joined[$id]->greaterThan($at) ? $joined[$id] : $at;
            }
        }

        return $since;
    }

    private static function since(Shop $shop): ?CarbonImmutable
    {
        return $shop->alt_pack_since === null ? null : CarbonImmutable::instance($shop->alt_pack_since);
    }
}
