<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\Product;
use App\Support\Numeric;
use App\Support\PackSize;
use Illuminate\Support\Facades\Config;

final class DropEvaluator
{
    private const int BC_SCALE = 4;

    /**
     * @param  string  $newPrice  in the reference's own basis — per unit when
     *                            the reference has one, per pack otherwise
     * @param  string|null  $newPackPrice  always pack money, or null when there
     *                                     is no pack price to report
     */
    public function evaluate(Product $product, string $newPrice, ReferenceValue $ref, ?string $newPackPrice = null): DropOutcome
    {
        $reference = Numeric::str($ref->value);
        $newPrice = Numeric::str($newPrice);

        $dropPercent = bccomp($reference, '0', self::BC_SCALE) > 0
            ? bcmul(bcdiv(bcsub($reference, $newPrice, self::BC_SCALE), $reference, self::BC_SCALE), '100', self::BC_SCALE)
            : '0';

        $dropAbsolute = $this->packDifference($product, $ref, $newPackPrice ?? $newPrice);

        // The two defaults come from two bands on purpose. A percentage banded
        // on a pack price would be quantity-dependent — two economically
        // identical offers either side of €25 a pack would get 15% and 10% for
        // no reason a shopper would recognise — while a money default banded on
        // a price per kilo sits in a band nobody set it for.
        $defaults = TierDefaults::forReference($ref);

        // A product with a target alerts only on what its owner set: an empty
        // drop threshold is off there, not the default. Asked only when one is
        // empty, because it reads the plan and the shops inside the row lock.
        $useDefaults = ($product->drop_threshold_pct !== null && $product->drop_threshold_abs !== null)
            || ! $product->hasActiveTarget();

        $thresholdPct = match (true) {
            $product->drop_threshold_pct !== null => (string) $product->drop_threshold_pct,
            $useDefaults => (string) $defaults['pct'],
            default => null,
        };

        $thresholdAbs = match (true) {
            $dropAbsolute === null => null,
            $product->drop_threshold_abs !== null => (string) $product->drop_threshold_abs,
            $useDefaults => (string) ($defaults['abs'] ?? TierDefaults::for($ref->value)['abs']),
            default => null,
        };

        $belowThreshold = ($dropAbsolute !== null && $thresholdAbs !== null && $this->meets($dropAbsolute, $thresholdAbs))
            || ($thresholdPct !== null && $this->meets($dropPercent, $thresholdPct));

        return new DropOutcome(
            belowThreshold: $belowThreshold,
            needsConfirmation: $this->needsConfirmation($dropPercent),
            referencePrice: $ref->packBasis(),
            referenceKind: $ref->kind,
            dropAbsolute: $dropAbsolute,
            dropPercent: $dropPercent,
            thresholdAbs: $thresholdAbs,
            thresholdPct: $thresholdPct,
            referenceUnitPrice: $ref->isUnitBasis() ? $ref->value : null,
            newUnitPrice: $ref->isUnitBasis() ? $newPrice : null,
            comparisonUnit: $ref->unit,
        );
    }

    /**
     * Money off the pack — but only when the reference and the winner sell the
     * same amount.
     *
     * Moving from 500 g at €5 to 1 kg at €8 is a genuine 20% fall per kilo and
     * a €3 *rise* in outlay. Reporting −€3 as a saving is wrong, and the
     * reverse direction is worse: a large positive figure that mostly means
     * buying less. Null says there is no money figure here.
     */
    private function packDifference(Product $product, ReferenceValue $ref, string $newPackPrice): ?string
    {
        $referencePack = $ref->packBasis();

        if ($referencePack === null) {
            return null;
        }

        if ($ref->isUnitBasis() && ! $this->sameSizeAsWinner($product, $ref)) {
            return null;
        }

        return bcsub(Numeric::str($referencePack), Numeric::str($newPackPrice), self::BC_SCALE);
    }

    private function sameSizeAsWinner(Product $product, ReferenceValue $ref): bool
    {
        if ($ref->packQuantity === null || $ref->packUnit === null) {
            return false;
        }

        $winner = $product->best_value_pack_quantity === null || ! is_string($product->best_value_pack_unit)
            ? null
            : PackSize::of((float) $product->best_value_pack_quantity, $product->best_value_pack_unit);

        return PackSize::of($ref->packQuantity, $ref->packUnit)?->isSameSizeAs($winner) === true;
    }

    /**
     * A drop this deep is not notified on one reading. `DetectDrop` asks the
     * shop's previous successful reading to agree first — one mis-extraction
     * (a unit price, a "from" price, another variant) reads exactly like this.
     */
    private function needsConfirmation(string $dropPercent): bool
    {
        $ceiling = (string) Config::integer('dipcatch.drops.confirm_above_pct');

        return bccomp(Numeric::str($dropPercent), Numeric::str($ceiling), self::BC_SCALE) >= 0;
    }

    private function meets(string $drop, string $threshold): bool
    {
        return bccomp(Numeric::str($drop), Numeric::str($threshold), self::BC_SCALE) >= 0;
    }
}
