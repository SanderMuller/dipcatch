<?php declare(strict_types=1);

namespace App\Actions\Products;

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
     * The target is written only when `$values` carries it: an untouched
     * field leaves the owner's target, the unit it was set in and its shop as
     * they are.
     *
     * @param  array{unit_price_target?: ?string, unit_price_target_unit?: ?string, unit_price_target_shop_id?: ?string}  $values
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
                app(LatchIfMet::class)($locked);
            }
        });
    }
}
