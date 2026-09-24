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
     * itself.
     *
     * @return list<array{value: string, below: bool, pro?: bool}>
     */
    public static function of(Product $product): array
    {
        $rules = [];

        if ($product->target_price !== null) {
            $rules[] = ['value' => MoneyFormatter::format((string) $product->target_price, $product->currency), 'below' => true];
        }

        $unitLabel = UnitWord::labelFor($product->comparablePacks()->unit());

        // Without a unit the figure would read as a pack price, and a free
        // account's target is kept but not checked.
        if ($product->unit_price_target !== null && $unitLabel !== '') {
            $rules[] = [
                'value' => MoneyFormatter::unitPrice((string) $product->unit_price_target, $product->currency) . $unitLabel,
                'below' => true,
                'pro' => $product->user?->entitlements()->allowsUnitPriceAlerts() !== true,
            ];
        }

        // An empty drop threshold is not off: the drop check falls back to a
        // default, read here from the same reference it uses. With a target
        // set it is off, as in DropEvaluator.
        $reference = $product->hasActiveTarget() ? null : app(Reference::class)->compute($product);
        $defaults = $reference === null ? null : TierDefaults::forReference($reference);

        if ($product->drop_threshold_pct !== null) {
            $rules[] = ['value' => __(':percent% drop', ['percent' => Numeric::trimmed((string) $product->drop_threshold_pct)]), 'below' => false];
        } elseif ($defaults !== null) {
            $rules[] = ['value' => __(':percent% drop (default)', ['percent' => Numeric::trimmed((string) $defaults['pct'])]), 'below' => false];
        }

        if ($product->drop_threshold_abs !== null) {
            $rules[] = ['value' => __(':amount drop', ['amount' => MoneyFormatter::format((string) $product->drop_threshold_abs, $product->currency)]), 'below' => false];
        } elseif ($defaults !== null && $defaults['abs'] !== null) {
            $rules[] = ['value' => __(':amount drop (default)', ['amount' => MoneyFormatter::format((string) $defaults['abs'], $product->currency)]), 'below' => false];
        }

        return $rules;
    }
}
