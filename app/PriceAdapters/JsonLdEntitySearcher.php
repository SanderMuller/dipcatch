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

        if (in_array('Product', $types, strict: true)) {
            $matched = JsonLdMatch::attempt($entity, $url, $variantKey);

            if ($matched !== null && JsonLdMatch::isStrong($entity, $url, $variantKey)) {
                $state->matched = true;

                return $matched;
            }

            if ($matched !== null) {
                $state->namesPageOnly ??= $matched;
            } else {
                $state->product ??= $entity;
            }
        }

        if (in_array('ProductGroup', $types, strict: true)) {
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

        $matches = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            /** @var array<string, mixed> $variant */
            if (! in_array('Product', JsonLdEntities::typesOf($variant), strict: true)) {
                continue;
            }

            $matched = JsonLdMatch::attempt($variant, $url, $variantKey);

            if ($matched !== null && JsonLdMatch::isStrong($variant, $url, $variantKey)) {
                $state->matched = true;

                return $matched;
            }

            if ($matched !== null) {
                $matches[] = $matched;

                continue;
            }

            $candidate = self::candidateFor($variant);
            if ($candidate !== null) {
                $state->variants[] = $candidate;
            }

            $state->product ??= $variant;
        }

        // One variant whose URL fits the request identifies it, even when
        // that URL names no variant of its own — a group listing a
        // queryless entry is naming its default. Several fitting variants
        // identify nothing, so they join the chooser instead.
        if (count($matches) === 1) {
            $state->matched = true;

            return $matches[0];
        }

        foreach ($matches as $match) {
            $candidate = self::candidateFor($match[0]);
            if ($candidate !== null) {
                $state->variants[] = $candidate;
            }
        }

        return null;
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
        foreach (JsonLdMatch::KEY_FIELDS as $field) {
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
