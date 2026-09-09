<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Reads the price and currency out of a schema.org Offer.
 *
 * Shared, because an offer states them in more than one place: directly, in
 * a `priceSpecification`, as an AggregateOffer's `lowPrice`, or under a
 * non-spec key (dirk.nl writes `Price`). When the variant chooser read only
 * the direct fields, a page whose offers carried their prices in
 * specifications produced no choices at all and quietly priced the first
 * offer.
 */
final readonly class JsonLdOfferPrice
{
    /**
     * @param  array<string, mixed>  $offer
     */
    public static function price(array $offer): ?string
    {
        if (in_array('AggregateOffer', JsonLdEntities::typesOf($offer), strict: true)) {
            return PriceNormalizer::fromMixed($offer['lowPrice'] ?? null);
        }

        $normalized = PriceNormalizer::fromMixed($offer['price'] ?? $offer['Price'] ?? null);
        if ($normalized !== null) {
            return $normalized;
        }

        $spec = self::firstPriceSpec($offer['priceSpecification'] ?? null);
        if ($spec !== null) {
            return PriceNormalizer::fromMixed($spec['price'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    public static function currency(array $offer): ?string
    {
        $currency = JsonLdEntities::nonEmptyString($offer['priceCurrency'] ?? null);
        if ($currency !== null) {
            return $currency;
        }

        $spec = self::firstPriceSpec($offer['priceSpecification'] ?? null);
        if ($spec !== null) {
            return JsonLdEntities::nonEmptyString($spec['priceCurrency'] ?? null);
        }

        return null;
    }

    /**
     * `priceSpecification` may be a single object or a list of
     * (Unit)PriceSpecification entries — pick the first usable one.
     *
     * @return array<string, mixed>|null
     */
    private static function firstPriceSpec(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['@type']) || isset($value['price'])) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (is_array($entry) && (isset($entry['@type']) || isset($entry['price']))) {
                    /** @var array<string, mixed> $entry */
                    return $entry;
                }
            }
        }

        return null;
    }
}
