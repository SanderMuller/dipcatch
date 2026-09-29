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
    /** Marks a key this app made up, rather than one the shop published. */
    public const string SYNTHESISED_PREFIX = 'variant-';

    /**
     * What a synthesised key is hashed from. Anything the shop can change
     * without the variant becoming a different thing to buy stays out.
     *
     * @var list<string>
     */
    private const array STABLE_KEY_FIELDS = ['name', 'variesBy', 'color', 'size', 'material', 'additionalProperty'];

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
        if (! $state->tied()) {
            return;
        }

        foreach ($state->topMatches() as $match) {
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
        if (JsonLdOfferVariants::weigh($entity, $url, $variantKey, $state)) {
            return;
        }

        $evaluation = JsonLdMatch::evaluate($entity, $url, $variantKey);

        // An entity with no usable offer answers nothing here, not even the
        // key — {@see self::scanVariants()} rules the other way.
        if ($evaluation->match === null) {
            $state->product ??= $entity;

            return;
        }

        if ($evaluation->keyMatched) {
            $state->keyMatched = true;
        }

        if ($evaluation->precision > 0) {
            $state->offer($evaluation->match, $evaluation->precision);

            return;
        }

        $state->namesPageOnly ??= $evaluation->match;
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

            $state->variantsSeen++;

            $evaluation = JsonLdMatch::evaluate($variant, $url, $variantKey);

            // A variant answering to the key has answered it, offer or no
            // offer — {@see self::weigh()} rules the other way.
            if ($evaluation->keyMatched) {
                $state->keyMatched = true;
            }

            if ($evaluation->match === null) {
                self::collect($variant, $state);

                continue;
            }

            if ($evaluation->precision > 0) {
                $state->offer($evaluation->match, $evaluation->precision);

                continue;
            }

            $fitsPageOnly[] = $evaluation->match;
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

        $price = JsonLdOfferPrice::price($offer);
        $currency = JsonLdOfferPrice::currency($offer);

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
     * The key a chooser prints for one variant, and the same key the matcher
     * recomputes when the caller sends it back. Public because those two are
     * different code paths, and a key only one of them can produce is a key
     * that never matches — see {@see JsonLdMatch::keyMatches()}.
     *
     * @param  array<string, mixed>  $variant
     */
    public static function variantKeyFor(array $variant): string
    {
        return JsonLdMatch::publishedKey($variant)
            ?? self::SYNTHESISED_PREFIX . substr(hash('xxh3', (string) json_encode(self::stableFields($variant))), 0, 12);
    }

    /**
     * The parts of a variant that identify it rather than describe its state.
     *
     * Hashing the whole entry made the key move whenever the shop moved its
     * price or sold out, so a key stored on Monday named nothing on Tuesday.
     * Name and options are what tells `1 Reep` from `12 Repen`, and they are
     * what a shopper picked.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private static function stableFields(array $variant): array
    {
        $stable = [];

        foreach (self::STABLE_KEY_FIELDS as $field) {
            if (array_key_exists($field, $variant)) {
                $stable[$field] = $variant[$field];
            }
        }

        // A variant naming none of them is rare enough to be worth a key that
        // at least tells two entries apart on the page it was read from.
        return $stable === [] ? $variant : $stable;
    }
}
