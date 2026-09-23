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
            default => null,
        };

        return is_string($word) ? $word : null;
    }

    /** "kilo", "litre", "piece" — the noun on its own, for a label. */
    public static function noun(?string $unit): ?string
    {
        $word = match ($unit) {
            'g' => __('kilo'),
            'ml' => __('litre'),
            'piece' => __('piece'),
            default => null,
        };

        return is_string($word) ? $word : null;
    }

    /** "/kg", "/l", "/piece" — for a figure. */
    public static function labelFor(?string $unit): string
    {
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
