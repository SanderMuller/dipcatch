<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Reads a structured availability value — schema.org JSON-LD, microdata, or
 * an Open Graph tag — into three answers rather than two.
 *
 * Every adapter used to return "in stock" for anything it did not recognise.
 * petmarkt.nl publishes `schema.org/LimitedAvailability` on a page that says
 * "Tijdelijk niet leverbaar" (verified 2026-09-09), so DipCatch reported a
 * sold-out product as available. A value we cannot map is now unknown, and
 * the caller decides what to do with that.
 */
final readonly class StockAvailability
{
    /** Values that state the product can be bought now. */
    private const array AVAILABLE = ['instock', 'instoreonly', 'onlineonly', 'available'];

    /** Values that state it cannot. */
    private const array UNAVAILABLE = ['outofstock', 'soldout', 'discontinued', 'outofservice'];

    /**
     * The verdict, and the raw signal it was read from so a caller can see
     * what DipCatch went on.
     *
     * @return array{0: ?bool, 1: ?string}
     */
    public static function read(mixed $availability): array
    {
        if (! is_string($availability) || trim($availability) === '') {
            return [null, null];
        }

        $signal = trim($availability);
        $token = self::token($signal);

        if (in_array($token, self::UNAVAILABLE, strict: true)) {
            return [false, $signal];
        }

        if (in_array($token, self::AVAILABLE, strict: true)) {
            return [true, $signal];
        }

        // LimitedAvailability, PreOrder, BackOrder, PreSale and anything a
        // shop invented: the page states something, but not that the product
        // can be bought right now.
        return [null, $signal];
    }

    /**
     * The bare value: `https://schema.org/OutOfStock`, `out of stock` and
     * `OUT_OF_STOCK` all reduce to `outofstock`.
     */
    private static function token(string $availability): string
    {
        $value = strtolower($availability);

        $slash = strrpos($value, '/');
        if ($slash !== false) {
            $value = substr($value, $slash + 1);
        }

        return (string) preg_replace('/[^a-z]/', '', $value);
    }
}
