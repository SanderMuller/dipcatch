<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Names one offer apart from its siblings, for the variant chooser.
 *
 * An offer usually repeats the product name, so listing that name twice puts
 * a choice to the user that only the prices distinguish — workable when one
 * pack is obviously bigger than the other, useless for two flavours. What
 * the offer states about itself comes first, then whatever the page uses to
 * tell this offer apart.
 */
final readonly class JsonLdOfferLabel
{
    /** Fields an offer uses to say which one it is, most readable first. */
    private const array DISTINGUISHING = ['size', 'sku', 'productID', 'gtin13', 'gtin'];

    /**
     * @param  array<string, mixed>  $entity
     * @param  array<string, mixed>  $offer
     */
    public static function for(array $entity, array $offer, string $key): string
    {
        $productName = JsonLdEntities::nonEmptyString($entity['name'] ?? null);
        $offerName = JsonLdEntities::nonEmptyString($offer['name'] ?? null);

        if ($offerName !== null && $offerName !== $productName) {
            return $offerName;
        }

        $distinguisher = self::distinguisher($offer) ?? $key;

        return $productName === null ? $distinguisher : $productName . ' — ' . $distinguisher;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private static function distinguisher(array $offer): ?string
    {
        foreach (self::DISTINGUISHING as $field) {
            $value = $offer[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        $url = JsonLdEntities::nonEmptyString($offer['url'] ?? null);
        $query = $url === null ? null : parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $query : null;
    }
}
