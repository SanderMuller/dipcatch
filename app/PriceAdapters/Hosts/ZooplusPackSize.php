<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\Support\NextData;
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
     * Null when the page names no variant it sells, or when that variant
     * does not sell at the price the snapshot tracked.
     */
    public static function read(string $url, string $html, string $trackedPrice): ?string
    {
        $offer = self::activeOffer($url, $html);

        return $offer === null ? null : self::packSize($offer, $trackedPrice);
    }

    /**
     * The first offer of the variant the page itself took from the URL, from
     * the Next.js page state. Null when the page names no variant and sells
     * more than one.
     *
     * @return array<mixed>|null
     */
    private static function activeOffer(string $url, string $html): ?array
    {
        $state = NextData::decode($html);
        $page = $state === null ? null : NextData::value($state, 'props.pageProps.pageLevelProps');
        $variants = is_array($page) ? NextData::value($page, 'productDetails.product.articleVariants') : null;

        if (! is_array($page) || ! is_array($variants) || $variants === []) {
            return null;
        }

        $active = $page['activeVariantFromUrl'] ?? self::queryVariant($url);
        $variantId = is_string($active) && str_contains($active, '.') ? substr($active, strrpos($active, '.') + 1) : null;

        $variant = $variantId === null
            ? (count($variants) === 1 ? array_first($variants) : null)
            : array_find($variants, static fn (mixed $row): bool => is_array($row) && is_int($row['variantId'] ?? null) && (string) $row['variantId'] === $variantId);

        $offer = is_array($variant) ? ($variant['offers'][0] ?? null) : null;

        return is_array($offer) ? $offer : null;
    }

    private static function queryVariant(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $variant = $query['activeVariant'] ?? $query['variantId'] ?? null;

        return is_string($variant) ? $variant : null;
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

        if ($current === null || $rate === null || $stated === null || ! self::pricesTheSnapshot($price, $current, $trackedPrice)) {
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
     * The offer is the one the snapshot priced: its regular price, or one of
     * its discounts.
     *
     * @param  array<mixed>  $price
     */
    private static function pricesTheSnapshot(array $price, float $current, string $trackedPrice): bool
    {
        $prices = [$current];

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
