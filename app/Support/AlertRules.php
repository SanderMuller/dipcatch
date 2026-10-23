<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\Drops\Reference;
use App\Services\Drops\TierDefaults;

/**
 * Every alert rule a product has, as the product page states them. Shared by
 * the page and its markdown copy, so the two cannot name different rules.
 */
final readonly class AlertRules
{
    /**
     * A price target carries `below` for the line under it; a drop names
     * itself. A unit-price target carries `pack`, what it comes to for the
     * size the live shops that state one agree on; a target per pack names
     * the shop whose pack it is.
     *
     * @return list<array{value: string, below: bool, pack?: string}>
     */
    public static function of(Product $product): array
    {
        $rules = [];
        $packs = $product->comparablePacks();
        $unitLabel = UnitWord::labelFor($packs->unit());
        $effective = $product->effectiveUnitPriceTarget();
        $pinned = $product->unit_price_target_unit === UnitTargetConversion::PACK ? $product->pinnedTargetShop() : null;

        if ($effective !== null && $pinned !== null) {
            $rules[] = [
                'value' => MoneyFormatter::format($effective, (string) $product->currency) . ' ' . UnitWord::labelFor(UnitTargetConversion::PACK),
                'below' => true,
                'pack' => __('the pack at :shop', ['shop' => ShopNames::label((string) $pinned->host)]),
            ];
        } elseif ($effective !== null && $unitLabel !== '') {
            // Without a unit the figure would read as a pack price.
            $rule = [
                'value' => MoneyFormatter::unitPrice($effective, $product->currency) . ' ' . $unitLabel,
                'below' => true,
            ];
            $size = $packs->sharedSize();
            $items = $packs->leadingItems();

            // Read as the price of one item, as the product page leads; the
            // target itself stays per litre or kilo, beside it.
            if ($items !== null) {
                $rule = [
                    'value' => MoneyFormatter::each($items->fromUnitPrice($effective), (string) $product->currency),
                    'below' => true,
                    'pack' => $rule['value'],
                ];
            } elseif ($size !== null) {
                $rule['pack'] = PackLine::forUnitPrice(Numeric::str($effective), (string) $product->currency, $size);
            }

            $rules[] = $rule;
        } elseif ($product->isUnitTargetSuspended() && $product->unit_price_target_unit === UnitTargetConversion::PACK) {
            $rules[] = [
                'value' => __(':target (paused: pick the pack you buy)', [
                    'target' => MoneyFormatter::format((string) $product->unit_price_target, (string) $product->currency) . ' ' . UnitWord::labelFor(UnitTargetConversion::PACK),
                ]),
                'below' => false,
            ];
        } elseif ($product->isUnitTargetSuspended()) {
            // In the unit it was set in, and named as not firing: read in
            // today's unit it would be a different price altogether.
            $rules[] = [
                'value' => __(':target (paused: this product now compares in another unit)', [
                    'target' => MoneyFormatter::unitPrice((string) $product->unit_price_target, $product->currency) . ' ' . UnitWord::labelFor($product->unit_price_target_unit),
                ]),
                'below' => false,
            ];
        }

        // Without an active target the default drop applies, read here from the
        // same reference the drop check uses. With one it is off, as in
        // DropEvaluator.
        $reference = $product->hasActiveTarget() ? null : app(Reference::class)->compute($product);
        $defaults = $reference === null ? null : TierDefaults::forReference($reference);

        if ($defaults !== null) {
            $rules[] = ['value' => __(':percent% drop (default)', ['percent' => Numeric::trimmed((string) $defaults['pct'])]), 'below' => false];
        }

        if ($defaults !== null && $defaults['abs'] !== null) {
            $rules[] = ['value' => __(':amount drop (default)', ['amount' => MoneyFormatter::format((string) $defaults['abs'], $product->currency)]), 'below' => false];
        }

        return $rules;
    }
}
