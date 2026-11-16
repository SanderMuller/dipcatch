<?php declare(strict_types=1);

namespace App\Support;

use App\Actions\Shops\SaveOwnerPackSize;
use App\Models\Shop;
use Illuminate\Validation\ValidationException;

/**
 * "How much is in this pack?" as a number and a unit picked from a list, so
 * nobody has to know how to write "36 pieces". The two make the text
 * {@see SaveOwnerPackSize} reads.
 */
final class OwnerPackSizeField
{
    /** The units on offer, keyed by the word the parser reads. */
    public const array UNITS = [
        'g' => 'g',
        'kg' => 'kg',
        'ml' => 'ml',
        'l' => 'l',
        'pieces' => 'pieces',
    ];

    /** The unit the list opens on: the one the product compares in, so most owners only type the number. */
    public static function defaultUnit(?string $comparisonUnit): string
    {
        return match ($comparisonUnit) {
            'ml' => 'ml',
            'piece' => 'pieces',
            default => 'g',
        };
    }

    /**
     * The owner's size as stored, not as shown: UnitWord::pack() rounds
     * 1234 g to 1.23 kg, and saving that back would move the size.
     *
     * @return array{amount: string, unit: string}|null
     */
    public static function of(Shop $shop): ?array
    {
        if ($shop->owner_pack_quantity === null || $shop->owner_pack_unit === null) {
            return null;
        }

        return [
            'amount' => Numeric::trimmed($shop->owner_pack_quantity),
            'unit' => $shop->owner_pack_unit === 'piece' ? 'pieces' : $shop->owner_pack_unit,
        ];
    }

    /**
     * The text to save: empty for an empty amount, which removes the size.
     *
     * @throws ValidationException on `$field` when the amount is not a number or the unit is not on the list
     */
    public static function text(?string $amount, ?string $unit, string $field): string
    {
        $amount = trim((string) $amount);

        if ($amount === '') {
            return '';
        }

        // A decimal comma, as Dutch shoppers write it.
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $amount) !== 1 || (float) str_replace(',', '.', $amount) <= 0 || ! array_key_exists((string) $unit, self::UNITS)) {
            throw ValidationException::withMessages([$field => __('Enter a number, such as 660 or 1.5.')]);
        }

        return str_replace(',', '.', $amount) . ' ' . $unit;
    }
}
