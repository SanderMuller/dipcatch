<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductDepartment;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * The shops of a product that sell one item in different counts: a single
 * 350 ml glass and a set of four. The ranking stays per litre or kilo; this
 * is what a person reads it as, "€5.75 each".
 *
 * It leads the display, ahead of the price per litre, only where the product
 * is a thing rather than its contents ({@see ProductDepartment::countsItems()}),
 * and only while the cheapest item is also the best value per litre, so the
 * figure shown never contradicts the ranking. Elsewhere the price per item
 * rides along beside the pack price.
 */
final readonly class ItemPricing
{
    /**
     * @param  array<string, float>  $counts  shop id => items in its pack
     */
    private function __construct(
        private array $counts,
        public float $itemSize,
        private bool $leads,
    ) {}

    /**
     * @param  Collection<int, Shop>  $shops
     * @param  array<string, ComparablePack>  $packs  keyed by shop id, in `$unit`
     */
    public static function of(ItemSizes $itemSizes, string $unit, Collection $shops, array $packs, bool $countsItems): ?self
    {
        $counts = $itemSizes->wholeItemCounts(array_map(fn (ComparablePack $pack): ?PackSize => $pack->size, $packs));
        $itemSize = $unit === 'piece' ? null : $itemSizes->between('piece', $unit);

        if ($counts === [] || $itemSize === null) {
            return null;
        }

        return new self($counts, $itemSize, $countsItems && self::cheapestItemIsBestValue($counts, $shops, $packs));
    }

    public function leads(): bool
    {
        return $this->leads;
    }

    public function countFor(Shop $shop): ?float
    {
        return $this->counts[(string) $shop->id] ?? null;
    }

    /** What one item costs at this shop, at four decimals, or null without a count or a price. */
    public function priceFor(Shop $shop): ?string
    {
        $count = $this->countFor($shop);

        return $count === null || $shop->current_price === null ? null : number_format((float) $shop->current_price / $count, PackSize::VALUE_DECIMALS, '.', '');
    }

    /** A price per litre or kilo as the price of one item: €52.55 /l is €18.39 for a 350 ml glass. */
    public function fromUnitPrice(string $unitPrice): string
    {
        return number_format((float) $unitPrice * $this->itemSize / 1000, PackSize::VALUE_DECIMALS, '.', '');
    }

    /** `4 × 350 ml` for a set, `350 ml` for one, or the size as it is without a count. */
    public function sizeLabel(Shop $shop, PackSize $size): string
    {
        $count = $this->countFor($shop);
        $item = $count === null ? null : PackSize::of($size->quantity / $count, $size->unit);

        if ($count === null || $count <= 1 || $item === null) {
            return UnitWord::pack($size);
        }

        return Numeric::trimmed(number_format($count, 2, '.', '')) . ' × ' . UnitWord::pack($item);
    }

    /**
     * Whether the shop with the lowest price per item also has the lowest
     * price per unit. A pack a little off a whole number of items can rank
     * differently in the two, and the figure shown must agree with the
     * "Best value" chip.
     *
     * @param  array<string, float>  $counts
     * @param  Collection<int, Shop>  $shops
     * @param  array<string, ComparablePack>  $packs
     */
    private static function cheapestItemIsBestValue(array $counts, Collection $shops, array $packs): bool
    {
        $perItem = [];
        $perUnit = [];

        foreach ($shops as $shop) {
            $id = (string) $shop->id;
            $size = ($packs[$id] ?? null)?->size;

            if (! isset($counts[$id]) || $size === null || $shop->current_price === null) {
                continue;
            }

            $perItem[$id] = (float) $shop->current_price / $counts[$id];
            $perUnit[$id] = (float) $shop->current_price / $size->quantity;
        }

        if ($perItem === []) {
            return false;
        }

        asort($perItem);
        asort($perUnit);

        return array_key_first($perItem) === array_key_first($perUnit);
    }
}
