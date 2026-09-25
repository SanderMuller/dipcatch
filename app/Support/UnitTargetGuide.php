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
     * Only packs that take part in the per-unit comparison, in the best-value
     * ranking's own order, so the first is the best value.
     *
     * `perPack` is how many comparison units one pack holds: 1.5 for a 1.5 L
     * bottle compared per litre, 8 for a box of 8 compared per piece.
     *
     * @return list<array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float}>
     */
    public function packs(): array
    {
        $packs = $this->product->comparablePacks();
        $choices = [];

        // The shops the ranking and the alert use: a dead or sold-out shop's
        // pack is not a price anyone can act on.
        foreach ($packs->rankedPerUnit($this->product->eligibleShops()) as $shop) {
            $size = $packs->for($shop)?->size;

            if ($size !== null) {
                $choices[] = self::packChoice((string) $shop->id, (string) $shop->host, $size, $shop->current_price);
            }
        }

        return $choices;
    }

    /**
     * A drop alert restated as a price per unit, off today's best per-unit
     * price: a percentage off the unit price, a money amount off the best
     * pack. With both set the drop check alerts on whichever is met first,
     * so the easier of the two carries over. Null when there is nothing to
     * restate, or the saving leaves less than the smallest target.
     *
     * @param  list<array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float}>|null  $packs  from packs(), when the caller has them
     * @return array{unit: string, drops: list<string>, percentUnder: int, packPrice: string, pack: string}|null
     */
    public function switchFromDrop(?string $percent, ?string $amount, ?array $packs = null): ?array
    {
        $best = ($packs ?? $this->packs())[0] ?? null;

        if ($best === null || $best['unitPrice'] === null) {
            return null;
        }

        // Decimal strings throughout: a float cut to four decimals turns 4.2
        // into 4.1999.
        $bestUnit = self::decimal($best['unitPrice']);
        $perPack = self::decimal($best['perPack']);
        $candidates = [];

        if (is_numeric($percent) && (float) $percent > 0 && (float) $percent < 100) {
            $candidates[] = [
                'unit' => bcmul($bestUnit, bcsub('1', bcdiv(Numeric::str($percent), '100', 10), 10), 10),
                'drop' => Numeric::trimmed($percent) . '%',
            ];
        }

        if (is_numeric($amount) && $best['price'] !== null && (float) $amount > 0 && (float) $amount < $best['price']) {
            $candidates[] = [
                'unit' => bcdiv(bcsub(self::decimal($best['price']), Numeric::str($amount), 10), $perPack, 10),
                'drop' => MoneyFormatter::format($amount, (string) $this->product->currency),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $drops = array_column($candidates, 'drop');
        usort($candidates, fn (array $a, array $b): int => bccomp($b['unit'], $a['unit'], 10));
        $unit = bcadd($candidates[0]['unit'], '0', 4);

        if (bccomp($unit, '0.0001', 4) < 0) {
            return null;
        }

        return [
            'unit' => Numeric::trimmed($unit),
            'drops' => $drops,
            'percentUnder' => (int) round((1 - (float) $unit / (float) $bestUnit) * 100),
            'packPrice' => bcadd(bcmul($unit, $perPack, 10), '0', 2),
            'pack' => $best['pack'],
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
     * @return array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float}
     */
    public static function packChoice(string $shopId, string $host, PackSize $size, mixed $price): array
    {
        $price = is_numeric($price) ? (string) $price : null;

        return [
            'shopId' => $shopId,
            'host' => $host,
            'pack' => UnitWord::pack($size),
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
