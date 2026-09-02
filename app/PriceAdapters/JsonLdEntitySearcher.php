<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Visitor over the JSON-LD entity stream. It never stops at the first
 * entity that fits: matches compete on precision in
 * {@see JsonLdSearchState}, so a page listing its canonical URL before the
 * variant the request names still prices the variant. Entities that fit
 * nothing become chooser candidates.
 */
final readonly class JsonLdEntitySearcher
{
    /**
     * Inspect a single entity, recording what it offers in
     * {@see JsonLdSearchState}. Nothing is returned early: the caller reads
     * the winner off the state once every entity has had its turn.
     *
     * @param  array<string, mixed>  $entity
     */
    public function consider(array $entity, string $url, ?AdapterContext $context, JsonLdSearchState $state): void
    {
        $types = JsonLdEntities::typesOf($entity);
        $variantKey = $context?->variantKey;

        if (in_array('Product', $types, strict: true)) {
            self::weigh($entity, $url, $variantKey, $state);
        }

        if (in_array('ProductGroup', $types, strict: true)) {
            $state->productGroup ??= $entity;
            self::scanVariants($entity, $url, $variantKey, $state);
        }

        if ($state->shop === null && JsonLdEntities::isOfferType($types)) {
            $state->shop = $entity;
        }
    }

    /**
     * Close the scan. Entities that tied on precision are the question
     * itself — the page states no way to tell them apart — so they become
     * the choices the caller offers.
     */
    public function finish(JsonLdSearchState $state): void
    {
        if (! $state->tied) {
            return;
        }

        foreach ($state->topMatches as $match) {
            $candidate = self::candidateFor($match[0]);

            if ($candidate !== null) {
                $state->variants[] = $candidate;
            }
        }
    }

    /**
     * Record where a single Product stands: an entity naming a variant
     * competes on precision, one naming only the page is held as a
     * fallback, and one naming neither is just the weakest candidate.
     *
     * @param  array<string, mixed>  $entity
     */
    private static function weigh(array $entity, string $url, ?string $variantKey, JsonLdSearchState $state): void
    {
        $matched = JsonLdMatch::attempt($entity, $url, $variantKey);

        if ($matched === null) {
            $state->product ??= $entity;

            return;
        }

        $precision = JsonLdMatch::precision($entity, $url, $variantKey);

        if ($precision > 0) {
            $state->offer($matched, $precision);

            return;
        }

        $state->namesPageOnly ??= $matched;
    }

    /**
     * Schema.org `hasVariant` traversal: every variant that fits the
     * request competes on precision; the rest become chooser candidates.
     *
     * @param  array<string, mixed>  $productGroup
     */
    private static function scanVariants(array $productGroup, string $url, ?string $variantKey, JsonLdSearchState $state): void
    {
        $variants = $productGroup['hasVariant'] ?? null;

        if (! is_array($variants)) {
            return;
        }

        $fitsPageOnly = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            /** @var array<string, mixed> $variant */
            if (! in_array('Product', JsonLdEntities::typesOf($variant), strict: true)) {
                continue;
            }

            $matched = JsonLdMatch::attempt($variant, $url, $variantKey);
            $precision = $matched === null ? -1 : JsonLdMatch::precision($variant, $url, $variantKey);

            if ($matched !== null && $precision > 0) {
                $state->offer($matched, $precision);

                continue;
            }

            if ($matched !== null) {
                $fitsPageOnly[] = $matched;

                continue;
            }

            self::collect($variant, $state);
        }

        // One variant fitting the request identifies it even when its URL
        // names no variant of its own — a group listing a queryless entry
        // is naming its default. Several fitting variants identify nothing,
        // so they join the chooser instead.
        if (count($fitsPageOnly) === 1) {
            $state->offer($fitsPageOnly[0], 0);

            return;
        }

        foreach ($fitsPageOnly as $match) {
            self::collect($match[0], $state);
        }
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private static function collect(array $variant, JsonLdSearchState $state): void
    {
        $candidate = self::candidateFor($variant);

        if ($candidate !== null) {
            $state->variants[] = $candidate;
        }

        $state->product ??= $variant;
    }

    /**
     * Build a display-ready VariantCandidate from a hasVariant entry, or
     * null if the variant has no parseable price/currency.
     *
     * @param  array<string, mixed>  $variant
     */
    public static function candidateFor(array $variant): ?VariantCandidate
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
