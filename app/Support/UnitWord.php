<?php declare(strict_types=1);

namespace App\Support;

/**
 * How a comparison unit is written for a reader.
 *
 * One place decides it, so an e-mail, a page and an alert never call the same
 * unit by two names. A null code means the product compared pack prices, and
 * every method here says so by answering null rather than by guessing "kilo".
 */
final class UnitWord
{
    /** "per kilo", "per litre", "per piece" — for a sentence. */
    public static function forCode(?string $unit): ?string
    {
        $word = match ($unit) {
            'g' => __('per kilo'),
            'ml' => __('per litre'),
            'piece' => __('per piece'),
            ItemPricing::UNIT => __('per item'),
            UnitTargetConversion::PACK => __('per pack'),
            default => null,
        };

        return is_string($word) ? $word : null;
    }

    /** "per litre", or "per item" where the price per item leads ({@see ItemPricing}). */
    public static function forComparison(ComparablePacks $packs): ?string
    {
        if (! $packs->leadsWithItems()) {
            return self::forCode($packs->unit());
        }

        $word = __('per item');

        return is_string($word) ? $word : null;
    }

    /** "kilo", "litre", "piece" — the noun on its own, for a label. */
    public static function noun(?string $unit): ?string
    {
        $word = match ($unit) {
            'g' => __('kilo'),
            'ml' => __('litre'),
            'piece' => __('piece'),
            ItemPricing::UNIT => __('item'),
            UnitTargetConversion::PACK => __('pack'),
            default => null,
        };

        return is_string($word) ? $word : null;
    }

    /** "/kg", "/l", "/piece", "/pack" — for a figure. */
    public static function labelFor(?string $unit): string
    {
        if ($unit === UnitTargetConversion::PACK) {
            return (string) __('/pack');
        }

        return PackSize::of(1.0, $unit ?? '')?->label() ?? '';
    }

    /** `500 g`, `1.5 kg`, `330 ml`, `1.5 L`, `8 pieces` — a pack as a shopper names it. */
    public static function pack(PackSize $size): string
    {
        $number = fn (float $value): string => Numeric::trimmed(number_format($value, 2, '.', ''));

        return match ($size->unit) {
            'g' => $size->quantity >= 1000 ? $number($size->quantity / 1000) . ' kg' : $number($size->quantity) . ' g',
            'ml' => $size->quantity >= 1000 ? $number($size->quantity / 1000) . ' L' : $number($size->quantity) . ' ml',
            default => trans_choice(':count piece|:count pieces', (int) $size->quantity, ['count' => $number($size->quantity)]),
        };
    }
}
