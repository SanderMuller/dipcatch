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
     * Whether the entity identifies the requested variant, rather than
     * merely sitting on the requested page.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function isStrong(array $entity, string $url, ?string $variantKey): bool
    {
        if ($variantKey !== null && self::keyMatches($entity, $variantKey)) {
            return true;
        }

        $entityUrl = JsonLdEntities::nonEmptyString($entity['url'] ?? null);

        return $entityUrl !== null && EntityUrl::namesVariant($entityUrl, $url);
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function attempt(array $entity, string $url, ?string $variantKey): ?array
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
}
