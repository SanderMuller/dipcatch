<?php declare(strict_types=1);

namespace App\Support;

use App\Charts\PriceHistorySeries;
use App\Models\Product;
use App\Models\Shop;

/**
 * What a person needs to set a target in the terms they think in: the packs
 * the shops sell, and the prices they have seen.
 */
final readonly class UnitTargetGuide
{
    public function __construct(private Product $product) {}

    /**
     * The packs the shops sell, so the per-unit target can be set in the
     * terms a shopper thinks in: a price for this bottle, this bag, this box.
     * Only packs that take part in the per-unit comparison, in the best-value
     * ranking's own order, so the first is the best value.
     *
     * `perPack` is how many comparison units one pack holds: 1.5 for a 1.5 L
     * bottle compared per litre, 8 for a box of 8 compared per piece.
     *
     * `ownerSize` is the size the owner stated for the shop, which the form
     * lets them change or remove; `shopSays` is the shop's own size when it
     * now states one that overrides it.
     *
     * @return list<array{shopId: string, host: string, shop: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, ownerSize: ?string, shopSays: ?string}>
     */
    public function packs(): array
    {
        $packs = $this->product->comparablePacks();
        $items = $packs->leadingItems();
        $choices = [];

        // The shops the ranking and the alert use: a dead or sold-out shop's
        // pack is not a price anyone can act on.
        foreach ($packs->rankedPerUnit($this->product->eligibleShops()) as $shop) {
            $size = $packs->for($shop)?->size;

            if ($size !== null) {
                $choices[] = [
                    ...self::packChoice((string) $shop->id, (string) $shop->host, $size, $shop->current_price, $items?->sizeLabel($shop, $size)),
                    ...self::ownerSizeOf($shop),
                ];
            }
        }

        return $choices;
    }

    /**
     * The packs a target can be set on while no shop can win per unit: one
     * per shop a shopper can buy from, the shop the product was added with
     * first. A price for one of them is compared with that shop's price only,
     * so a single bar elsewhere never meets a price meant for a 12-pack.
     *
     * The shape of {@see packs()}, one pack being the unit: `perPack` is 1.
     *
     * @return list<array{shopId: string, host: string, shop: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, ownerSize: ?string, shopSays: ?string}>
     */
    public function pinnablePacks(): array
    {
        return $this->product->eligibleShops()
            ->sort(fn (Shop $a, Shop $b): int => [$a->created_at, (string) $a->id] <=> [$b->created_at, (string) $b->id])
            ->map(function (Shop $shop): array {
                $size = $shop->packSize();
                $price = is_numeric($shop->current_price) ? (float) $shop->current_price : null;

                return [
                    'shopId' => (string) $shop->id,
                    'host' => (string) $shop->host,
                    'shop' => ShopNames::label((string) $shop->host),
                    'pack' => $size === null ? (string) __('pack') : UnitWord::pack($size),
                    'perPack' => 1.0,
                    'price' => $price,
                    'unitPrice' => $price,
                    ...self::ownerSizeOf($shop),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Sizes the owner stated in another unit than the product compares in,
     * which keeps their shop out of {@see packs()}. The form still offers to
     * remove them.
     *
     * @return list<array{shopId: string, shop: string, size: string}>
     */
    public function strandedOwnerSizes(): array
    {
        $unit = $this->product->comparablePacks()->unit();

        return $this->product->shops
            ->filter(fn (Shop $shop): bool => $shop->isOwnerSized() && $shop->ownerPackSize()?->unit !== $unit)
            ->map(fn (Shop $shop): array => [
                'shopId' => (string) $shop->id,
                'shop' => ShopNames::label((string) $shop->host),
                'size' => UnitWord::pack($shop->ownerPackSize() ?? throw new \LogicException('An owner-sized shop has an owner size.')),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{ownerSize: ?string, shopSays: ?string}
     */
    private static function ownerSizeOf(Shop $shop): array
    {
        $owner = $shop->ownerPackSize();
        $own = $owner === null ? null : $shop->packSize();

        return [
            'ownerSize' => $owner === null ? null : UnitWord::pack($owner),
            'shopSays' => $own === null ? null : UnitWord::pack($own),
        ];
    }

    /**
     * A per-unit target `$percent` under a pack price, for a pack of `$perPack`
     * units, with what it comes to for that pack. The saving comes off the
     * pack price before the division, so €1.00 for 3 at 40% off is €0.20
     * each rather than €0.1999. Null when the column cannot hold it.
     *
     * @param  numeric-string  $packPrice
     * @return array{unit: string, packPrice: string}|null
     */
    public static function percentUnder(string $packPrice, float $perPack, int $percent): ?array
    {
        $perPack = self::decimal($perPack);
        $pack = bcmul($packPrice, bcsub('1', bcdiv((string) $percent, '100', 10), 10), 10);

        return self::storable(bcdiv($pack, $perPack, 10), $perPack);
    }

    /**
     * A per-unit figure cut to the four decimals the column keeps, never
     * rounded up past the saving it was worked out from.
     *
     * @param  numeric-string  $unit
     * @param  numeric-string  $perPack
     * @return array{unit: string, packPrice: string}|null
     */
    private static function storable(string $unit, string $perPack): ?array
    {
        $unit = bcadd($unit, '0', 4);

        // Outside what `unit_price_target` (decimal 12,4) can hold.
        if (bccomp($unit, '0.0001', 4) < 0 || bccomp($unit, '99999999.9999', 4) > 0) {
            return null;
        }

        return [
            'unit' => Numeric::trimmed($unit),
            'packPrice' => bcadd(bcmul($unit, $perPack, 10), '0', 2),
        ];
    }

    /** @return numeric-string */
    private static function decimal(float $value): string
    {
        return Numeric::str(sprintf('%.10F', $value));
    }

    /**
     * One pack as the unit-target component reads it — also for a shop that
     * is not saved yet, on the create form.
     *
     * @return array{shopId: string, host: string, shop: string, pack: string, perPack: float, price: ?float, unitPrice: ?float}
     */
    public static function packChoice(string $shopId, string $host, PackSize $size, mixed $price, ?string $label = null): array
    {
        $price = is_numeric($price) ? (string) $price : null;

        return [
            'shopId' => $shopId,
            'host' => $host,
            'shop' => ShopNames::label($host),
            'pack' => $label ?? UnitWord::pack($size),
            'perPack' => $size->unit === 'piece' ? $size->quantity : $size->quantity / 1000,
            'price' => $price === null ? null : (float) $price,
            'unitPrice' => $price === null ? null : $size->unitPriceValueFor($price),
        ];
    }

    /**
     * The lowest price per unit on the product's price chart, in the history
     * the account may read: the chart's per-unit line, which follows the
     * best value. Null with fewer than two points.
     *
     * @return array{lowest: float}|null
     */
    public function history(): ?array
    {
        $comparisonUnit = $this->product->comparablePacks()->unit();
        $unit = new PriceHistorySeries($this->product)->data()['unit'];

        // A history kept in another unit than the alert compares in says
        // nothing about this target.
        if ($unit === null || $comparisonUnit === null || $unit['unit'] !== $comparisonUnit) {
            return null;
        }

        $points = array_values(array_filter($unit['points'], fn (?float $point): bool => $point !== null && $point > 0));

        if (count($points) < 2) {
            return null;
        }

        return ['lowest' => min($points)];
    }
}
