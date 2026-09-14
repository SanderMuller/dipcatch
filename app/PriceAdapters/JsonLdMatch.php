<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Decides whether a JSON-LD entity is the product that was asked for, and
 * how firmly. A weak match names the page; a strong one names the variant,
 * and only a strong one ends the search.
 */
final readonly class JsonLdMatch
{
    /** Schema.org variant identifiers we'll match against in order. */
    public const array KEY_FIELDS = ['productID', 'sku', 'gtin13', 'gtin'];

    /**
     * Decide what one entity answers, in a single pass.
     *
     * The key test feeds all three answers, so it runs once. Precision
     * scores how precisely the entity names the request: a pinned variant
     * key is the most precise answer there is; below it, the entity URL
     * scores by how much of the request it states. Zero means it names the
     * page but no variant of it, and -1 that it states no URL at all.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function evaluate(array $entity, string $url, ?string $variantKey): JsonLdEvaluation
    {
        $keyMatched = $variantKey !== null && self::keyMatches($entity, $variantKey);

        return new JsonLdEvaluation(
            keyMatched: $keyMatched,
            match: self::matchFor($entity, $url, $keyMatched),
            precision: self::precisionFor($entity, $url, $keyMatched),
        );
    }

    /**
     * The entity paired with the offer to read its price from, when the
     * entity names the request and states an offer at all.
     *
     * @param  array<string, mixed>  $entity
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function matchFor(array $entity, string $url, bool $keyMatched): ?array
    {
        if (! $keyMatched && ! JsonLdEntities::urlMatches($entity, $url)) {
            return null;
        }

        $shop = JsonLdEntities::pickOfferFromProduct($entity['offers'] ?? null);

        return $shop === null ? null : [$entity, $shop];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private static function precisionFor(array $entity, string $url, bool $keyMatched): int
    {
        if ($keyMatched) {
            return PHP_INT_MAX;
        }

        $entityUrl = JsonLdEntities::nonEmptyString($entity['url'] ?? null);

        return $entityUrl === null ? -1 : EntityUrl::precision($entityUrl, $url);
    }

    /**
     * Whether this entity — a Product, a variant, or a single Offer — is the
     * one the caller pinned with `variant_key`.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function keyMatches(array $entity, string $key): bool
    {
        foreach (self::KEY_FIELDS as $field) {
            $value = $entity[$field] ?? null;
            if (is_scalar($value) && (string) $value === $key) {
                return true;
            }
        }

        // Allow storing a full variant URL as the key.
        if (JsonLdEntities::urlMatches($entity, $key)) {
            return true;
        }

        return false;
    }
}
