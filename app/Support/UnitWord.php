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

    /** "/kg", "/l", "/stuk" — for a figure. */
    public static function labelFor(?string $unit): string
    {
        return PackSize::of(1.0, $unit ?? '')?->label() ?? '';
    }
}
