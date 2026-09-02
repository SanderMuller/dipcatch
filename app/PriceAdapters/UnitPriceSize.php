<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Support\PackSize;

/**
 * Recovers a pack size from a schema.org `UnitPriceSpecification`.
 *
 * A shop that publishes "€ 416,60 per liter" beside a € 59,99 offer has
 * stated the pack size without naming it: 59.99 / 416.60 = 0.144 litre.
 * That beats reading a size out of the product title, which only works
 * while the title happens to carry one (zooplus.nl, verified 2026-09-02).
 */
final readonly class UnitPriceSize
{
    /**
     * UN/CEFACT codes → the unit {@see PackSize} normalizes to,
     * with the multiplier that converts to it.
     *
     * @var array<string, array{0: string, 1: float}>
     */
    private const array UNITS = [
        'KGM' => ['g', 1000.0],
        'GRM' => ['g', 1.0],
        'LTR' => ['ml', 1000.0],
        'DLT' => ['ml', 100.0],
        'CLT' => ['ml', 10.0],
        'MLT' => ['ml', 1.0],
        'H87' => ['stuks', 1.0],
        'C62' => ['stuks', 1.0],
        'EA' => ['stuks', 1.0],
    ];

    /**
     * The pack size the offer's unit price implies, as text
     * {@see PackSize} can parse, or null when the offer states
     * no usable unit price.
     *
     * @param  array<string, mixed>  $offer
     */
    public static function from(array $offer, string $price): ?string
    {
        $unitPrice = self::unitPriceSpecification($offer);

        if ($unitPrice === null) {
            return null;
        }

        $rate = PriceNormalizer::fromMixed($unitPrice['price'] ?? null);
        $reference = $unitPrice['referenceQuantity'] ?? null;

        if ($rate === null || (float) $rate <= 0.0 || ! is_array($reference)) {
            return null;
        }

        $unit = self::unit($reference['unitCode'] ?? null);
        $per = is_numeric($reference['value'] ?? null) ? (float) $reference['value'] : null;

        if ($unit === null || $per === null || $per <= 0.0 || (float) $price <= 0.0) {
            return null;
        }

        [$name, $multiplier] = $unit;
        $quantity = ((float) $price / (float) $rate) * $per * $multiplier;

        if ($quantity <= 0.0 || ! is_finite($quantity)) {
            return null;
        }

        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') . ' ' . $name;
    }

    /**
     * The specification that prices the unit. An offer also carries member
     * and sale prices in the same list — reading one of those as a rate
     * would invent a pack size out of a discount.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>|null
     */
    private static function unitPriceSpecification(array $offer): ?array
    {
        $specifications = $offer['priceSpecification'] ?? null;

        if (! is_array($specifications)) {
            return null;
        }

        // A single specification may be given unwrapped.
        if (isset($specifications['@type'])) {
            $specifications = [$specifications];
        }

        foreach ($specifications as $specification) {
            if (! is_array($specification)) {
                continue;
            }

            $type = $specification['priceType'] ?? null;

            if (is_string($type) && str_ends_with($type, 'UnitPrice')) {
                /** @var array<string, mixed> $specification */
                return $specification;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: float}|null
     */
    private static function unit(mixed $code): ?array
    {
        return is_string($code) ? (self::UNITS[strtoupper($code)] ?? null) : null;
    }
}
