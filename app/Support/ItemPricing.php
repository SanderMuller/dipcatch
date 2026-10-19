<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductDepartment;
use App\Models\Product;
use App\Models\Shop;

/**
 * The shops of a product that sell one item in different counts: a single
 * 350 ml glass and a set of four. The ranking stays per litre or kilo; this
 * is what a person reads it as, "€5.75 each".
 *
 * It leads the display, ahead of the price per litre, only where the product
 * is a thing rather than its contents ({@see ProductDepartment::countsItems()}),
 * and only while every pack is exactly its count of the item, so the figure
 * shown never contradicts the ranking. Elsewhere the price per item rides
 * along beside the pack price.
 */
final readonly class ItemPricing
{
    /** The unit a series is in once it reads per item: not a pack unit, so nothing stores it. */
    public const string UNIT = 'item';

    /** How far a pack may sit from its count of the item, in ml or g, while the price per item leads: the precision sizes are stored at. */
    private const float EXACT_TOLERANCE = 0.01;

    /**
     * @param  array<string, float>  $counts  shop id => items in its pack
     */
    private function __construct(
        private array $counts,
        public float $itemSize,
        private bool $leads,
    ) {}

    /**
     * @param  array<string, ComparablePack>  $packs  keyed by shop id, in `$unit`
     */
    public static function of(ItemSizes $itemSizes, string $unit, array $packs, bool $countsItems): ?self
    {
        $counts = $itemSizes->wholeItemCounts(array_map(fn (ComparablePack $pack): ?PackSize => $pack->size, $packs));
        $itemSize = $unit === 'piece' ? null : $itemSizes->between('piece', $unit);

        if ($counts === [] || $itemSize === null) {
            return null;
        }

        return new self($counts, $itemSize, $countsItems && self::isExact($counts, $itemSize, $packs));
    }

    /**
     * How much one item holds, in ml or g, where the product reads per item;
     * else null. Kept on an alert when it fires, so the alert reads as the
     * product page did.
     */
    public static function itemSizeOf(Product $product): ?float
    {
        $packs = $product->comparablePacks();

        return $packs->leadingItems()?->itemSize;
    }

    /**
     * A price per unit as an alert states it: `€16.42 /l`, or `€5.75 each`
     * with the `$itemSize` the alert kept.
     */
    public static function figure(string $unitPrice, string $currency, string $label, ?float $itemSize): string
    {
        return $itemSize === null
            ? MoneyFormatter::unitPrice($unitPrice, $currency) . ' ' . $label
            : MoneyFormatter::each(self::perItem($unitPrice, $itemSize), $currency);
    }

    /** This, while the price per item leads the display; else null. */
    public function leading(): ?self
    {
        return $this->leads ? $this : null;
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
        return self::perItem($unitPrice, $this->itemSize);
    }

    /** {@see fromUnitPrice()} for an item size kept apart from its shops. */
    public static function perItem(string $unitPrice, float $itemSize): string
    {
        return number_format((float) $unitPrice * $itemSize / 1000, PackSize::VALUE_DECIMALS, '.', '');
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
     * Whether every pack is exactly its count of the item. Leading with the
     * price per item converts the alert and the chart by the item size, while
     * a row divides its pack price by its count; only for exact packs do the
     * two agree. It also keeps the figure shown in line with "Best value":
     * for exact packs the price per item ranks the shops as the price per
     * unit does. A 1380 ml four-pack beside 350 ml glasses would show €5.50
     * each in its row and €5.58 each on the chart.
     *
     * @param  array<string, float>  $counts
     * @param  array<string, ComparablePack>  $packs
     */
    private static function isExact(array $counts, float $itemSize, array $packs): bool
    {
        return array_all($counts, fn (float $count, string $id): bool => ($size = ($packs[$id] ?? null)?->size) !== null
            && abs($size->quantity - $count * $itemSize) <= self::EXACT_TOLERANCE);
    }
}
