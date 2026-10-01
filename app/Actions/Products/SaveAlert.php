<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Support\Numeric;
use Illuminate\Support\Facades\DB;

/**
 * Saves the alert step 3 of adding a product set. When the person kept the
 * suggested per-unit target and the price already meets it, the target is
 * marked as notified: they saw this offer while adding the product, and the
 * next one is worth an alert.
 */
final class SaveAlert
{
    /**
     * @param  array{drop_threshold_pct: ?string, drop_threshold_abs: ?string, target_price: ?string, unit_price_target: ?string}  $values
     * @param  string|null  $suggestedTarget  The per-unit target the suggestion filled in, if the person used it.
     */
    public function __invoke(Product $product, array $values, ?string $suggestedTarget): void
    {
        $keptSuggestion = $suggestedTarget !== null && $values['unit_price_target'] !== null
            && bccomp(Numeric::str($values['unit_price_target']), Numeric::str($suggestedTarget), 4) === 0;

        DB::transaction(function () use ($product, $values, $keptSuggestion): void {
            // The row lock a price check takes, so a check cannot run between
            // the target and its latch and alert on the offer just seen.
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->forceFill($values)->save();

            if ($keptSuggestion) {
                self::latchIfMet($locked);
            }
        });
    }

    /** Never raises a latch the product already has. */
    private static function latchIfMet(Product $product): void
    {
        $target = $product->unit_price_target;
        $shop = $product->bestValueShop();
        $value = $shop?->unitPriceValue();
        $price = $shop?->unitPrice();

        if ($target === null || $value === null || $price === null || ! DetectUnitPriceTarget::meets($value, (string) $target)) {
            return;
        }

        $latched = $product->unit_price_notified;

        if ($latched !== null && bccomp(Numeric::str((string) $latched), Numeric::str($price), 4) <= 0) {
            return;
        }

        $product->forceFill(['unit_price_notified' => $price, 'unit_price_notified_at' => now()])->save();
    }
}
