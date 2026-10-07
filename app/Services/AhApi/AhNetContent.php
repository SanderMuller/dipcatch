<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\Support\Numeric;

/**
 * The sizes AH's trade item states a pack in, beside the product card's
 * single `salesUnitSize`. Iglo fish fingers are `20 stuks` on the card and
 * both 20 pieces and 560 g here.
 */
final readonly class AhNetContent
{
    /**
     * Every size the trade item states the pack in — `20 st` and `560 g` for
     * Iglo fish fingers, verified live on 2026-10-06. Null `netContent`, as
     * on eggs and nappies, is no sizes at all.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function sizes(array $payload): array
    {
        $entries = data_get($payload, 'tradeItem.measurements.netContent');

        if (! is_array($entries)) {
            return [];
        }

        $sizes = [];

        foreach ($entries as $entry) {
            $value = data_get($entry, 'value');
            $unit = match (data_get($entry, 'measurementUnitCode.value')) {
                'g' => 'g',
                'kg' => 'kg',
                'ml' => 'ml',
                'l' => 'l',
                'st' => 'stuks',
                default => null,
            };

            if (is_numeric($value) && (float) $value > 0 && $unit !== null) {
                $sizes[] = Numeric::trimmed(number_format((float) $value, 3, '.', '')) . ' ' . $unit;
            }
        }

        return $sizes;
    }
}
