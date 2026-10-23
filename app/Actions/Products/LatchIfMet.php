<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Support\Numeric;

/**
 * Marks a target as already sent when today's price meets it: the owner
 * sees this offer as they set the target, or heard about it before the
 * target was rewritten, and the next offer is the one worth an alert.
 *
 * Saved apart from the target. `Product::booted()` clears the latch when the
 * target changes, so the target has to be saved first.
 */
final readonly class LatchIfMet
{
    /** Never raises a latch the product already has. */
    public function __invoke(Product $product): void
    {
        $basis = $product->targetBasis();

        if ($basis === null || ! DetectUnitPriceTarget::meets($basis->value, $basis->target)) {
            return;
        }

        $latched = DetectUnitPriceTarget::latchIn($product, $basis->unit, $product->comparablePacks());

        if ($latched !== null && bccomp(Numeric::str(number_format($latched, 4, '.', '')), Numeric::str($basis->price), 4) <= 0) {
            return;
        }

        $product->forceFill([
            'unit_price_notified' => $basis->price,
            'unit_price_notified_unit' => $basis->unit,
            'unit_price_notified_at' => now(),
        ])->save();
    }
}
