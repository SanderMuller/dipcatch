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

        $spec = self::sellingSpec($offer['priceSpecification'] ?? null);
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

        // The currency of the spec that set the price first: a struck `$100`
        // listed before the `€80` selling spec must not make it `80 USD`.
        foreach ([self::sellingSpec($offer['priceSpecification'] ?? null), self::firstPriceSpec($offer['priceSpecification'] ?? null)] as $spec) {
            $currency = $spec === null ? null : JsonLdEntities::nonEmptyString($spec['priceCurrency'] ?? null);

            if ($currency !== null) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * The shop's own earlier price, from a `StrikethroughPrice` spec in the
     * offer's currency. A `ListPrice` is a recommended price, not a price the
     * shop charged, so it is never a claim.
     *
     * @param  array<string, mixed>  $offer
     */
    public static function claimedRegularPrice(array $offer, string $currency): ?string
    {
        foreach (self::specs($offer['priceSpecification'] ?? null) as $spec) {
            $type = $spec['priceType'] ?? null;
            $specCurrency = JsonLdEntities::nonEmptyString($spec['priceCurrency'] ?? null);

            if (is_string($type) && preg_match('~(^|/)StrikethroughPrice$~', $type) === 1
                && ($specCurrency === null || strcasecmp($specCurrency, $currency) === 0)) {
                return PriceNormalizer::fromMixed($spec['price'] ?? null);
            }
        }

        return null;
    }

    /**
     * The seller the offer names, when a marketplace offer names one.
     *
     * @param  array<string, mixed>  $offer
     */
    public static function seller(array $offer): ?string
    {
        $seller = $offer['seller'] ?? null;

        return is_array($seller) ? JsonLdEntities::nonEmptyString($seller['name'] ?? null) : null;
    }

    /**
     * Whether a `priceSpecification` states a reference price rather than the
     * price to pay: `StrikethroughPrice` (the shop's earlier price) or
     * `ListPrice` (a recommended price).
     *
     * @param  array<string, mixed>  $spec
     */
    private static function isReferencePrice(array $spec): bool
    {
        $type = $spec['priceType'] ?? null;

        return is_string($type) && preg_match('~(^|/)(StrikethroughPrice|ListPrice)$~', $type) === 1;
    }

    /**
     * The first usable spec that states the price to pay.
     *
     * @return array<string, mixed>|null
     */
    private static function sellingSpec(mixed $value): ?array
    {
        foreach (self::specs($value) as $spec) {
            if (! self::isReferencePrice($spec)) {
                return $spec;
            }
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
        return self::specs($value)[0] ?? null;
    }

    /**
     * Every usable entry of a `priceSpecification`, a single object or a list.
     *
     * @return list<array<string, mixed>>
     */
    private static function specs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (isset($value['@type']) || isset($value['price'])) {
            /** @var array<string, mixed> $value */
            return [$value];
        }

        if (! array_is_list($value)) {
            return [];
        }

        $specs = [];

        foreach ($value as $entry) {
            if (is_array($entry) && (isset($entry['@type']) || isset($entry['price']))) {
                /** @var array<string, mixed> $entry */
                $specs[] = $entry;
            }
        }

        return $specs;
    }
}
