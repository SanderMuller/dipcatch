<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\Support\PackSize;

/**
 * The pack size a zooplus or bitiba page states for one variant, from its
 * Next.js page state. There, the regular price and its rate per unit sit in
 * one offer object beside the stated size, a pairing the page's JSON-LD does
 * not make.
 */
final readonly class ZooplusPackSize
{
    /**
     * Null when no variant can be told apart, or when the one found does not
     * sell at the price the snapshot tracked.
     *
     * @param  ?string  $variantKey  The variant the user chose, as the JSON-LD
     *                               chooser keyed it: a sku, a gtin or a URL.
     */
    public static function read(string $url, string $html, string $trackedPrice, ?string $variantKey = null): ?string
    {
        $state = ZooplusPageState::read($html);

        if ($state === null) {
            return null;
        }

        $variant = self::variant($state, [$variantKey, $state->pageVariant, ZooplusPageState::queryVariant($url)], $trackedPrice);
        $offer = $variant['offers'][0] ?? null;

        return is_array($offer) ? self::packSize($offer, $trackedPrice) : null;
    }

    /**
     * The first hint that names a variant wins. With no hint, a page that
     * sells one variant, or only one at the tracked price, has answered
     * anyway.
     *
     * @param  list<?string>  $hints
     * @return array<mixed>|null
     */
    private static function variant(ZooplusPageState $state, array $hints, string $trackedPrice): ?array
    {
        $named = $state->named($hints);

        if ($named !== null) {
            return $named;
        }

        $variants = $state->variants;
        $selling = array_values(array_filter($variants, static function (array $row) use ($trackedPrice): bool {
            $price = $row['offers'][0]['price'] ?? null;

            return is_array($price) && self::sellsAt($price, $trackedPrice);
        }));

        return count($variants) === 1 ? $variants[0] : (count($selling) === 1 ? $selling[0] : null);
    }

    /**
     * The stated `unitQuantity`, confirmed by price over rate from the same
     * offer object. The stated value is rounded to two decimals and the rate
     * to cents, so the stated value stands while the division agrees with it
     * within the rate's rounding: 31.99 / 3.20 is 9.997 kg, and 10 kg stands.
     * Only a division that is sharper than that proves the stated value was
     * rounded: 58.99 / 409.65 is 0.144 l against a stated 0.14.
     *
     * @param  array<mixed>  $offer
     */
    private static function packSize(array $offer, string $trackedPrice): ?string
    {
        $price = $offer['price'] ?? null;
        $unit = $offer['unit'] ?? null;

        if (! is_array($price) || ! is_array($unit)) {
            return null;
        }

        $current = self::number($price['currentPrice']['value'] ?? null);
        $rate = self::number($unit['unitPriceRaw'] ?? null);
        $stated = self::number($unit['unitQuantity'] ?? null);

        if ($current === null || $rate === null || $stated === null || ! self::sellsAt($price, $trackedPrice)) {
            return null;
        }

        $derived = $current / $rate;
        $derivedError = $derived * 0.005 / $rate;
        $gap = abs($derived - $stated);

        // A page state that moved on, or another variant's offer.
        if ($gap > 0.005 + $derivedError) {
            return null;
        }

        $name = self::unitName($unit);

        if ($name === null) {
            return null;
        }

        // Whole grams, millilitres or pieces: the rate's cents carry no finer
        // reading, and a size that moves with the price is not a pack size.
        $quantity = $gap > $derivedError * 1.01
            ? round($derived, $name === 'stuks' ? 0 : 3)
            : $stated;

        $amount = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

        return PackSize::parse($amount . ' ' . $name) === null ? null : $amount . ' ' . $name;
    }

    /**
     * The unit as {@see PackSize} reads it. A name holding a number, such as
     * "100g", would be read as a quantity, so it is refused.
     *
     * @param  array<mixed>  $unit
     */
    private static function unitName(array $unit): ?string
    {
        if (($unit['unitNameRaw'] ?? null) === 'piece') {
            return 'stuks';
        }

        $name = $unit['unitName'] ?? null;

        return is_string($name) && $name !== '' && preg_match('/\d/', $name) !== 1 ? $name : null;
    }

    /**
     * The offer sells at the tracked price: its regular price, or one of its
     * discounts.
     *
     * @param  array<mixed>  $price
     */
    private static function sellsAt(array $price, string $trackedPrice): bool
    {
        $prices = [self::number($price['currentPrice']['value'] ?? null)];

        foreach (is_array($price['discounts'] ?? null) ? $price['discounts'] : [] as $discount) {
            $prices[] = is_array($discount) ? self::number($discount['discountedPriceRaw'] ?? null) : null;
        }

        return array_any($prices, static fn (?float $candidate): bool => $candidate !== null && abs($candidate - (float) $trackedPrice) < 0.005);
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? ($value > 0 ? (float) $value : null) : null;
    }
}
