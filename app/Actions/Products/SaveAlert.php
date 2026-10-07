<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Support\Numeric;
use App\Support\UnitTargetConversion;
use Illuminate\Support\Facades\DB;

/**
 * Saves a product's alert fields. When the person kept the suggested per-unit
 * target and the price already meets it, the target is marked as notified:
 * they saw this offer when they set it, and the next one is worth an alert.
 */
final class SaveAlert
{
    /**
     * A per-unit target is written only when `$values` carries it: an
     * untouched field leaves the owner's target, and the unit it was set in,
     * as they are.
     *
     * @param  array{drop_threshold_pct: ?string, drop_threshold_abs: ?string, target_price: ?string, unit_price_target?: ?string, unit_price_target_unit?: ?string}  $values
     * @param  string|null  $suggestedTarget  The per-unit target the suggestion filled in, if the person used it.
     */
    public function __invoke(Product $product, array $values, ?string $suggestedTarget): void
    {
        $unitTarget = $values['unit_price_target'] ?? null;
        $keptSuggestion = $suggestedTarget !== null && $unitTarget !== null
            && bccomp(Numeric::str($unitTarget), Numeric::str($suggestedTarget), 4) === 0;

        DB::transaction(function () use ($product, $values, $keptSuggestion): void {
            // The row lock a price check takes, so a check cannot run between
            // the target and its latch and alert on the offer just seen.
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->forceFill($values);

            if (array_key_exists('unit_price_target', $values)) {
                $locked->unit_price_target_effective = UnitTargetConversion::effective(
                    $values['unit_price_target'],
                    $locked->unit_price_target_unit,
                    $locked->comparablePacks(),
                );
            }

            $locked->save();

            if ($keptSuggestion) {
                self::latchIfMet($locked);
            }
        });
    }

    /** Never raises a latch the product already has. */
    private static function latchIfMet(Product $product): void
    {
        $target = $product->effectiveUnitPriceTarget();
        $packs = $product->comparablePacks();
        $unit = $packs->unit();
        $shop = $product->bestValueShop();
        $value = $shop === null ? null : $packs->unitPriceValueOf($shop);
        $price = $shop === null ? null : $packs->unitPriceOf($shop);

        if ($target === null || $unit === null || $value === null || $price === null || ! DetectUnitPriceTarget::meets($value, $target)) {
            return;
        }

        $latched = DetectUnitPriceTarget::latchIn($product, $unit, $packs);

        if ($latched !== null && bccomp(Numeric::str(number_format($latched, 4, '.', '')), Numeric::str($price), 4) <= 0) {
            return;
        }

        $product->forceFill(['unit_price_notified' => $price, 'unit_price_notified_unit' => $unit, 'unit_price_notified_at' => now()])->save();
    }
}
