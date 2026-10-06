<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\JsonLdEntities;
use App\PriceAdapters\JsonLdOfferVariants;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NextData;
use App\Support\PackSize;
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
final readonly class PetsAtHomeAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'petsathome';
    }

    public function ownedHosts(): array
    {
        return ['petsathome.com'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $rewritten = self::withoutSubscriptionOffers($html);
        if ($rewritten === null) {
            return ExtractionResult::failed('petsathome_extraction_failed');
        }

        $result = new JsonLdAdapter()->extract($url, $rewritten, $context);
        if ($result->isSuccess() && $result->snapshot !== null && $result->snapshot->packSize === null) {
            $size = self::packSizeOf($html, $result->snapshot);

            return $size === null ? $result : $result->withSnapshot($result->snapshot->withPackSize($size));
        }

        if ($result->isSuccess() || $result->isAmbiguous()) {
            return $result;
        }

        return ExtractionResult::failed('petsathome_extraction_failed');
    }

    /**
     * The size of the bag that was priced. The JSON-LD names the product
     * without it ("AATU Chicken Adult Dry Dog Food"), and the page state
     * states it per variant: `baseProduct.products[]` keyed by the offer's
     * sku, as `netAmount` and `uomValue` (5, "kg") and as `label` ("5kg").
     * Only a variant selling at the price read is taken.
     */
    private static function packSizeOf(string $html, ShopSnapshot $snapshot): ?string
    {
        $sku = $snapshot->raw['offer']['sku'] ?? null;
        $sku = is_int($sku) ? (string) $sku : $sku;
        $state = NextData::decode($html);
        $products = $state === null ? null : NextData::value($state, 'props.pageProps.baseProduct.products');

        if (! is_string($sku) || ! is_array($products)) {
            return null;
        }

        $variant = array_find($products, static fn (mixed $row): bool => is_array($row) && is_scalar($row['id'] ?? null) && (string) $row['id'] === $sku);
        $price = is_array($variant) ? ($variant['price']['base'] ?? null) : null;

        $statePrice = PriceNormalizer::fromMixed($price);
        $readPrice = PriceNormalizer::fromMixed($snapshot->price);

        if (! is_array($variant) || $statePrice === null || $readPrice === null || bccomp($statePrice, $readPrice, 2) !== 0) {
            return null;
        }

        $amount = $variant['netAmount'] ?? null;
        $unit = $variant['uomValue'] ?? null;
        $candidates = [
            (is_int($amount) || is_float($amount)) && is_string($unit) ? $amount . ' ' . $unit : null,
            is_string($variant['label'] ?? null) ? $variant['label'] : null,
        ];

        return array_find($candidates, static fn (?string $text): bool => $text !== null && PackSize::parse($text) !== null);
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
