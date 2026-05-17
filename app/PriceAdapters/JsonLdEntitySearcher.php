<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Visitor over the JSON-LD entity stream. Prefers an exact URL/variant-key
 * match over weaker product/offer fallbacks accumulated in
 * {@see JsonLdSearchState}. Also collects variant candidates so the caller
 * can surface an ambiguous-result chooser when multiple variants share the
 * requested URL.
 */
final readonly class JsonLdEntitySearcher
{
    /** Schema.org variant identifiers we'll match against in order. */
    private const array KEY_FIELDS = ['productID', 'sku', 'gtin13', 'gtin'];

    /**
     * Inspect a single entity. Returns a [product, offer] tuple if this
     * entity is a hard URL / variant-key match worth returning immediately,
     * otherwise updates {@see JsonLdSearchState} with weaker candidates and
     * variant candidates, and returns null.
     *
     * @param  array<string, mixed>  $entity
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public function consider(array $entity, string $url, ?AdapterContext $context, JsonLdSearchState $state): ?array
    {
        $types = JsonLdEntities::typesOf($entity);
        $variantKey = $context?->variantKey;

        if (in_array('Product', $types, true)) {
            $matched = self::tryMatch($entity, $url, $variantKey);
            if ($matched !== null) {
                return $matched;
            }
            $state->product ??= $entity;
        }

        if (in_array('ProductGroup', $types, true)) {
            $state->productGroup ??= $entity;
            $matched = self::scanVariants($entity, $url, $variantKey, $state);
            if ($matched !== null) {
                return $matched;
            }
        }

        if ($state->shop === null && JsonLdEntities::isOfferType($types)) {
            $state->shop = $entity;
        }

        return null;
    }

    /**
     * Schema.org `hasVariant` traversal: prefer the variant whose `url` or
     * `productID`/`sku` matches the requested URL / persisted variant_key.
     * Variants that don't match are collected as candidates so the caller
     * can surface a chooser when more than one exists.
     *
     * @param  array<string, mixed>  $productGroup
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function scanVariants(array $productGroup, string $url, ?string $variantKey, JsonLdSearchState $state): ?array
    {
        $variants = $productGroup['hasVariant'] ?? null;
        if (! is_array($variants)) {
            return null;
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            /** @var array<string, mixed> $variant */
            if (! in_array('Product', JsonLdEntities::typesOf($variant), true)) {
                continue;
            }

            $matched = self::tryMatch($variant, $url, $variantKey);
            if ($matched !== null) {
                return $matched;
            }

            $candidate = self::candidateFor($variant);
            if ($candidate !== null) {
                $state->variants[] = $candidate;
            }

            $state->product ??= $variant;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function tryMatch(array $entity, string $url, ?string $variantKey): ?array
    {
        $matches = ($variantKey !== null && self::keyMatches($entity, $variantKey))
            || JsonLdEntities::urlMatches($entity, $url);

        if (! $matches) {
            return null;
        }

        $shop = JsonLdEntities::pickOfferFromProduct($entity['offers'] ?? null);
        if ($shop === null) {
            return null;
        }

        return [$entity, $shop];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private static function keyMatches(array $entity, string $key): bool
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

    /**
     * Build a display-ready VariantCandidate from a hasVariant entry, or
     * null if the variant has no parseable price/currency.
     *
     * @param  array<string, mixed>  $variant
     */
    private static function candidateFor(array $variant): ?VariantCandidate
    {
        $offer = JsonLdEntities::pickOfferFromProduct($variant['offers'] ?? null);
        if (! is_array($offer)) {
            return null;
        }

        $price = PriceNormalizer::fromMixed($offer['price'] ?? null);
        $currency = JsonLdEntities::nonEmptyString($offer['priceCurrency'] ?? null);

        if ($price === null || $currency === null) {
            return null;
        }

        return new VariantCandidate(
            key: self::variantKeyFor($variant),
            title: JsonLdEntities::nonEmptyString($variant['name'] ?? null) ?? 'Variant',
            price: $price,
            currency: strtoupper($currency),
        );
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private static function variantKeyFor(array $variant): string
    {
        foreach (self::KEY_FIELDS as $field) {
            $value = $variant[$field] ?? null;
            if (is_scalar($value)) {
                $str = (string) $value;
                if ($str !== '') {
                    return $str;
                }
            }
        }

        $url = JsonLdEntities::nonEmptyString($variant['url'] ?? null);
        if ($url !== null) {
            return $url;
        }

        return 'variant-' . substr(hash('xxh3', (string) json_encode($variant)), 0, 12);
    }
}
