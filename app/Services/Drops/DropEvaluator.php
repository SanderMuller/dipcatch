<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\Product;
use App\Support\Numeric;
use Illuminate\Support\Facades\Config;

final class DropEvaluator
{
    private const int BC_SCALE = 4;

    public function evaluate(Product $product, string $newPrice, ReferenceValue $ref): DropOutcome
    {
        $reference = Numeric::str($ref->value);
        $newPrice = Numeric::str($newPrice);

        $dropAbsolute = bcsub($reference, $newPrice, self::BC_SCALE);

        $dropPercent = bccomp($reference, '0', self::BC_SCALE) > 0
            ? bcmul(bcdiv(Numeric::str($dropAbsolute), $reference, self::BC_SCALE), '100', self::BC_SCALE)
            : '0';

        $tier = TierDefaults::for($ref->value);

        $thresholdPct = $product->drop_threshold_pct !== null
            ? (string) $product->drop_threshold_pct
            : (string) $tier['pct'];

        $thresholdAbs = $product->drop_threshold_abs !== null
            ? (string) $product->drop_threshold_abs
            : (string) $tier['abs'];

        $belowThreshold = $this->meetsAbsThreshold($dropAbsolute, $thresholdAbs)
            || $this->meetsPctThreshold($dropPercent, $thresholdPct);

        return new DropOutcome(
            belowThreshold: $belowThreshold,
            needsConfirmation: $this->needsConfirmation($dropPercent),
            referencePrice: $ref->value,
            referenceKind: $ref->kind,
            dropAbsolute: $dropAbsolute,
            dropPercent: $dropPercent,
            thresholdAbs: $thresholdAbs,
            thresholdPct: $thresholdPct,
        );
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

    private function meetsAbsThreshold(string $dropAbsolute, string $thresholdAbs): bool
    {
        return bccomp(Numeric::str($dropAbsolute), Numeric::str($thresholdAbs), self::BC_SCALE) >= 0;
    }

    private function meetsPctThreshold(string $dropPercent, string $thresholdPct): bool
    {
        return bccomp(Numeric::str($dropPercent), Numeric::str($thresholdPct), self::BC_SCALE) >= 0;
    }
}
