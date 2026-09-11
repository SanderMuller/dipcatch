<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\JsonLdEntities;
use App\PriceAdapters\JsonLdOfferVariants;
use App\PriceAdapters\ShopAdapter;
use JsonException;

/**
 * Host-specific adapter for petsathome.com.
 *
 * Product JSON-LD lists two Offers per SKU: "Standard price" and "Easy Repeat
 * subscription price" (verified 2026-09-11). Those duplicate SKUs also stop
 * the generic offer-variant walk, so JSON-LD would price the first offer —
 * which is the subscription when the page lists it first. DipCatch tracks
 * the shelf price, so subscription offers are dropped before JSON-LD runs.
 * Distinct Standard SKUs then become pack-size choices.
 */
final readonly class PetsAtHomeAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'petsathome';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'petsathome.com')) {
            return ExtractionResult::skip();
        }

        $rewritten = self::withoutSubscriptionOffers($html);
        if ($rewritten === null) {
            return ExtractionResult::failed('petsathome_extraction_failed');
        }

        $result = new JsonLdAdapter()->extract($url, $rewritten, $context);
        if ($result->isSuccess() || $result->isAmbiguous()) {
            return $result;
        }

        return ExtractionResult::failed('petsathome_extraction_failed');
    }

    private static function withoutSubscriptionOffers(string $html): ?string
    {
        $count = 0;
        $rewritten = preg_replace_callback(
            '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            static function (array $match): string {
                try {
                    $decoded = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return $match[0];
                }

                $filtered = json_encode(self::dropSubscriptionOffers($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                return str_replace($match[1], $filtered, $match[0]);
            },
            $html,
            count: $count,
        );

        if (! is_string($rewritten) || $count === 0) {
            return null;
        }

        return $rewritten;
    }

    private static function dropSubscriptionOffers(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            $items = [];
            foreach ($node as $item) {
                $items[] = self::dropSubscriptionOffers($item);
            }

            return $items;
        }

        /** @var array<string, mixed> $node */
        if (in_array('Product', JsonLdEntities::typesOf($node), strict: true) && array_key_exists('offers', $node)) {
            $node['offers'] = self::shelfOffers($node['offers']);
        }

        foreach (['@graph', 'hasVariant'] as $key) {
            if (array_key_exists($key, $node)) {
                $node[$key] = self::dropSubscriptionOffers($node[$key]);
            }
        }

        return $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function shelfOffers(mixed $offers): array
    {
        $kept = [];
        foreach (JsonLdOfferVariants::offerList($offers) as $offer) {
            if (self::isShelfOffer($offer)) {
                $kept[] = $offer;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private static function isShelfOffer(array $offer): bool
    {
        $description = JsonLdEntities::nonEmptyString($offer['description'] ?? null);
        if ($description === null) {
            return true;
        }

        $normalized = strtolower($description);

        return ! str_contains($normalized, 'subscription')
            && ! str_contains($normalized, 'easy repeat');
    }
}
