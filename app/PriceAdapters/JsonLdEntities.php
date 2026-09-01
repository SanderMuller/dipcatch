<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Low-level helpers for navigating schema.org JSON-LD entity trees. Split out
 * from `JsonLdAdapter` to keep that class focused on the adapter contract
 * (and to keep PHPStan's per-class cognitive-complexity score reasonable).
 */
final class JsonLdEntities
{
    /**
     * Yield top-level entities, expanding `@graph` arrays.
     *
     * @return iterable<array<string, mixed>>
     */
    public static function expandGraph(mixed $decoded): iterable
    {
        if (! is_array($decoded)) {
            return;
        }

        $graph = $decoded['@graph'] ?? null;
        if (is_array($graph)) {
            foreach ($graph as $entity) {
                if (is_array($entity)) {
                    /** @var array<string, mixed> $entity */
                    yield $entity;
                }
            }

            return;
        }

        if (array_is_list($decoded)) {
            foreach ($decoded as $entity) {
                if (is_array($entity)) {
                    /** @var array<string, mixed> $entity */
                    yield $entity;
                }
            }

            return;
        }

        /** @var array<string, mixed> $decoded */
        yield $decoded;
    }

    /**
     * Normalize the `@type` field to a list (which may contain a single null
     * when the entity has no `@type` at all).
     *
     * @param  array<string, mixed>  $entity
     * @return list<mixed>
     */
    public static function typesOf(array $entity): array
    {
        $type = $entity['@type'] ?? null;

        if (is_array($type)) {
            return array_values($type);
        }

        return [$type];
    }

    /**
     * @param  list<mixed>  $types
     */
    public static function isOfferType(array $types): bool
    {
        return in_array('Shop', $types, strict: true) || in_array('AggregateOffer', $types, strict: true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pickOfferFromProduct(mixed $shops): ?array
    {
        if (! is_array($shops)) {
            return null;
        }

        if (isset($shops['@type'])) {
            /** @var array<string, mixed> $shops */
            return $shops;
        }

        if (array_is_list($shops)) {
            foreach ($shops as $candidate) {
                if (is_array($candidate)) {
                    /** @var array<string, mixed> $candidate */
                    return $candidate;
                }
            }

            return null;
        }

        /** @var array<string, mixed> $shops */
        return $shops;
    }

    /**
     * Resolve schema.org `image` which may be a string, an array of strings,
     * or an `ImageObject` with a `url` key.
     */
    public static function firstImageUrl(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            foreach ($value as $candidate) {
                $resolved = self::firstImageUrl($candidate);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        $url = $value['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * JSON-LD lives inside a `<script>`, so the HTML parser never decodes it:
     * shops that HTML-escape their own data hand over a name like
     * `Lay&#39;s chips naturel` (spar.nl, verified 2026-09-02), which would
     * otherwise reach the product title verbatim.
     */
    public static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $decoded === '' ? null : $decoded;
    }

    /**
     * Loose equality between the requested URL and an entity's `url` field —
     * lowercased and stripped of query/fragment + trailing slash so e.g.
     * `?activeVariant=…` doesn't defeat the match.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function urlMatches(array $entity, string $url): bool
    {
        $entityUrl = self::nonEmptyString($entity['url'] ?? null);
        if ($entityUrl === null) {
            return false;
        }

        return self::canonicalizeUrl($entityUrl) === self::canonicalizeUrl($url);
    }

    private static function canonicalizeUrl(string $url): string
    {
        $stripped = strtok($url, '?#');

        return rtrim(strtolower(is_string($stripped) ? $stripped : $url), '/');
    }
}
