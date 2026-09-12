<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * A Product that states several offers rather than several variants.
 *
 * Some shops publish one pack size per Offer under a single Product, each
 * offer naming itself with its own `sku` and `?sku=` URL, and never mention
 * a ProductGroup — medpets.nl does (verified 2026-09-09). Reading the first
 * offer there priced a 24x100g box for a 12x100g request. The offers are
 * the variants, so they compete and, failing that, they become the choices.
 */
final readonly class JsonLdOfferVariants
{
    /**
     * Weigh a Product's offers as variants. Returns false when this Product
     * is not of that shape, so the caller weighs it as a single product.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function weigh(array $entity, string $url, ?string $variantKey, JsonLdSearchState $state): bool
    {
        $offers = self::offerList($entity['offers'] ?? null);

        if (! self::areSeparateVariants($offers)) {
            return false;
        }

        $pinned = self::pinned($offers, $variantKey);

        if ($pinned !== null) {
            $state->keyMatched = true;
            $state->offer([$entity, $pinned], PHP_INT_MAX);

            return true;
        }

        $named = self::namedByUrl($offers, $url);

        if ($named !== null) {
            $state->offer([$entity, $named[0]], $named[1]);

            return true;
        }

        $candidates = [];

        foreach ($offers as $offer) {
            $candidate = self::candidateFor($entity, $offer);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        // Fewer than two offers a shopper could choose between is not a
        // choice. A page pairing one real offer with a priceless stub still
        // has one price, and must return it rather than fail.
        if (count($candidates) < 2) {
            return false;
        }

        foreach ($candidates as $candidate) {
            $state->variants[] = $candidate;
        }

        $state->product ??= $entity;

        return true;
    }

    /**
     * Every offer an entity states, in document order.
     *
     * @return list<array<string, mixed>>
     */
    public static function offerList(mixed $offers): array
    {
        if (! is_array($offers)) {
            return [];
        }

        if (isset($offers['@type']) || ! array_is_list($offers)) {
            /** @var array<string, mixed> $offers */
            return [$offers];
        }

        $list = [];

        foreach ($offers as $offer) {
            if (is_array($offer)) {
                /** @var array<string, mixed> $offer */
                $list[] = $offer;
            }
        }

        return $list;
    }

    /**
     * The offer the caller pinned with `variant_key`, if exactly one answers
     * to it. `keyMatches()` also accepts a full variant URL, and URL
     * matching accepts a query subset, so a canonical offer listed first can
     * answer to a key that names a different offer. Two answers identify
     * nothing, so both become choices instead.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return array<string, mixed>|null
     */
    private static function pinned(array $offers, ?string $variantKey): ?array
    {
        if ($variantKey === null) {
            return null;
        }

        $matches = [];

        foreach ($offers as $offer) {
            if (self::keyFor($offer) === $variantKey) {
                // An offer naming itself with this exact key is the answer,
                // whatever else the page lists.
                return $offer;
            }

            if (JsonLdMatch::keyMatches($offer, $variantKey)) {
                $matches[] = $offer;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The offer the requested URL names, with how precisely it names it.
     *
     * The most precise offer wins rather than the first one listed: a
     * request for `?size=L&color=red` also matches the offer stating only
     * `?size=L`, and document order must not decide which price is read.
     * Two offers equally precise identify nothing, so neither is used.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private static function namedByUrl(array $offers, string $url): ?array
    {
        $best = null;
        $bestPrecision = 0;
        $tied = false;

        foreach ($offers as $offer) {
            $offerUrl = JsonLdEntities::nonEmptyString($offer['url'] ?? null);
            $precision = $offerUrl === null ? -1 : EntityUrl::precision($offerUrl, $url);

            if ($precision <= 0) {
                continue;
            }

            if ($precision > $bestPrecision) {
                $best = $offer;
                $bestPrecision = $precision;
                $tied = false;

                continue;
            }

            if ($precision === $bestPrecision) {
                $tied = true;
            }
        }

        return $best === null || $tied ? null : [$best, $bestPrecision];
    }

    /**
     * Whether these offers are distinct products rather than several ways to
     * buy one. Only offers that each name themselves — an own key field or
     * an own URL — can be chosen between, so a page listing two nameless
     * offers keeps the old behaviour of pricing the first.
     *
     * @param  list<array<string, mixed>>  $offers
     */
    private static function areSeparateVariants(array $offers): bool
    {
        if (count($offers) < 2) {
            return false;
        }

        $keys = [];

        foreach ($offers as $offer) {
            $key = self::keyFor($offer);

            if ($key === null) {
                return false;
            }

            $keys[$key] = true;
        }

        return count($keys) === count($offers);
    }

    /**
     * How this offer names itself, or null when it does not.
     *
     * @param  array<string, mixed>  $offer
     */
    private static function keyFor(array $offer): ?string
    {
        foreach (JsonLdMatch::KEY_FIELDS as $field) {
            $value = $offer[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return JsonLdEntities::nonEmptyString($offer['url'] ?? null);
    }

    /**
     * One offer, as a choice the caller can pick.
     *
     * @param  array<string, mixed>  $entity
     * @param  array<string, mixed>  $offer
     */
    private static function candidateFor(array $entity, array $offer): ?VariantCandidate
    {
        $price = JsonLdOfferPrice::price($offer);
        $currency = JsonLdOfferPrice::currency($offer);
        $key = self::keyFor($offer);

        if ($price === null || $currency === null || $key === null) {
            return null;
        }

        return new VariantCandidate(
            key: $key,
            title: JsonLdOfferLabel::for($entity, $offer, $key),
            price: $price,
            currency: strtoupper($currency),
        );
    }
}
