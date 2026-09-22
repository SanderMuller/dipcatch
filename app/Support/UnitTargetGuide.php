<?php declare(strict_types=1);

namespace App\Support;

use App\Charts\PriceHistorySeries;
use App\Models\Product;

/**
 * What a person needs to set a per-unit target in the terms they think in:
 * the packs the shops sell, and the per-unit prices they have seen.
 */
final readonly class UnitTargetGuide
{
    public function __construct(private Product $product) {}

    /**
     * The packs the shops sell, so the per-unit target can be set in the
     * terms a shopper thinks in: a price for this bottle, this bag, this box.
     * Only packs that take part in the per-unit comparison, cheapest per unit
     * first.
     *
     * `perPack` is how many comparison units one pack holds: 1.5 for a 1.5 L
     * bottle compared per litre, 8 for a box of 8 compared per piece.
     *
     * @return list<array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, bestValue: bool}>
     */
    public function packs(): array
    {
        $packs = $this->product->comparablePacks();
        $bestValueId = $this->product->bestValueShop()?->id;
        $choices = [];

        // The shops the ranking and the alert use: a dead or sold-out shop's
        // pack is not a price anyone can act on.
        foreach ($this->product->eligibleShops() as $shop) {
            $pack = $packs->for($shop);
            $size = $pack?->size;

            if ($pack === null || $size === null || ! $pack->canWin()) {
                continue;
            }

            $choices[] = self::packChoice((string) $shop->id, (string) $shop->host, $size, $shop->current_price, $shop->id === $bestValueId);
        }

        usort($choices, fn (array $a, array $b): int => ($a['unitPrice'] ?? PHP_FLOAT_MAX) <=> ($b['unitPrice'] ?? PHP_FLOAT_MAX));

        return $choices;
    }

    /**
     * One pack as the unit-target component reads it — also for a shop that
     * is not saved yet, on the create form.
     *
     * @return array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, bestValue: bool}
     */
    public static function packChoice(string $shopId, string $host, PackSize $size, mixed $price, bool $bestValue): array
    {
        $price = is_numeric($price) ? (string) $price : null;

        return [
            'shopId' => $shopId,
            'host' => $host,
            'pack' => self::packLabel($size),
            'perPack' => $size->unit === 'piece' ? $size->quantity : $size->quantity / 1000,
            'price' => $price === null ? null : (float) $price,
            'unitPrice' => $price === null ? null : $size->unitPriceValueFor($price),
            'bestValue' => $bestValue,
        ];
    }

    /**
     * The lowest price per unit on the product's price chart, in the history
     * the account may read: the chart's per-unit line, which follows the
     * cheapest shop. Null with fewer than two points.
     *
     * @return array{lowest: float}|null
     */
    public function history(): ?array
    {
        $suffix = ltrim(UnitWord::labelFor($this->product->comparablePacks()->unit()), '/');
        $datasets = new PriceHistorySeries($this->product)->data()['datasets'];
        $unit = array_find($datasets, fn (array $dataset): bool => ($dataset['yAxisID'] ?? null) === 'unit');
        $label = is_string($unit['label'] ?? null) ? $unit['label'] : '';

        // A history kept in another unit than the alert compares in says
        // nothing about this target.
        if ($unit === null || $suffix === '' || ! str_contains($label, 'per ' . $suffix)) {
            return null;
        }

        $data = is_array($unit['data'] ?? null) ? $unit['data'] : [];
        $points = array_values(array_filter($data, fn (mixed $point): bool => is_float($point) && $point > 0));

        if (count($points) < 2) {
            return null;
        }

        return ['lowest' => min($points)];
    }

    /** `500 g`, `1.5 kg`, `330 ml`, `1.5 L`, `8 pieces`. */
    private static function packLabel(PackSize $size): string
    {
        $number = fn (float $value): string => Numeric::trimmed(number_format($value, 2, '.', ''));

        return match ($size->unit) {
            'g' => $size->quantity >= 1000 ? $number($size->quantity / 1000) . ' kg' : $number($size->quantity) . ' g',
            'ml' => $size->quantity >= 1000 ? $number($size->quantity / 1000) . ' L' : $number($size->quantity) . ' ml',
            default => trans_choice(':count piece|:count pieces', (int) $size->quantity, ['count' => $number($size->quantity)]),
        };
    }
}
