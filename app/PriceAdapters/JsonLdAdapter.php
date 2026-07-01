<?php declare(strict_types=1);

namespace App\PriceAdapters;

use JsonException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts price from schema.org `<script type="application/ld+json">` blobs.
 * Low-level navigation helpers live in {@see JsonLdEntities}.
 */
final readonly class JsonLdAdapter implements ShopAdapter
{
    public function key(): string
    {
        return 'jsonld';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $crawler = self::crawler($html);
        $scripts = $crawler->filter('script[type="application/ld+json"]');

        if ($scripts->count() === 0) {
            return ExtractionResult::skip();
        }

        $state = new JsonLdSearchState();
        [$product, $shop] = $this->findProductAndOffer($scripts, $url, $context, $state);

        // Variant ambiguity wins over a weak fallback: when the page lists
        // multiple variants and the caller didn't pin one via context, ask
        // the user instead of silently guessing.
        if ($context?->variantKey === null && count($state->variants) > 1) {
            return ExtractionResult::ambiguous($state->variants);
        }

        if ($shop === null) {
            // No Product / ProductGroup / Offer entity at all → skip so the
            // weaker adapters (microdata, OG, generic) get a shot. Only fail
            // when we found a Product but couldn't extract an offer from it.
            if ($state->product === null && $state->productGroup === null) {
                return ExtractionResult::skip();
            }

            return ExtractionResult::failed('jsonld_no_offer');
        }

        return $this->buildSnapshot($product, $shop);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function findProductAndOffer(Crawler $scripts, string $url, ?AdapterContext $context, JsonLdSearchState $state): array
    {
        $searcher = new JsonLdEntitySearcher();

        foreach ($scripts as $node) {
            $decoded = $this->decodeScript($node->textContent);
            if ($decoded === null) {
                continue;
            }

            foreach (JsonLdEntities::expandGraph($decoded) as $entity) {
                $matched = $searcher->consider($entity, $url, $context, $state);
                if ($matched !== null) {
                    return $matched;
                }
            }

            if ($state->shop === null && $state->product !== null && isset($state->product['offers'])) {
                $state->shop = JsonLdEntities::pickOfferFromProduct($state->product['offers']);
            }
        }

        return $state->fallback();
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function decodeScript(string $text): array|null
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<int|string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>       $shop
     */
    private function buildSnapshot(?array $product, array $shop): ExtractionResult
    {
        $price = self::extractPrice($shop);
        if ($price === null) {
            return ExtractionResult::failed('jsonld_no_price');
        }

        $currency = self::extractCurrency($shop);
        if ($currency === null) {
            return ExtractionResult::failed('jsonld_no_currency');
        }

        $title = JsonLdEntities::nonEmptyString($product['name'] ?? null)
            ?? JsonLdEntities::nonEmptyString($shop['name'] ?? null)
            ?? 'Unknown';
        $imageUrl = JsonLdEntities::firstImageUrl($product['image'] ?? null)
            ?? JsonLdEntities::firstImageUrl($shop['image'] ?? null);

        return ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: $imageUrl,
            price: $price,
            currency: strtoupper($currency),
            inStock: self::extractInStock($shop),
            raw: ['offer' => $shop],
        ));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    /**
     * @param  array<string, mixed>  $shop
     */
    private static function extractPrice(array $shop): ?string
    {
        $types = JsonLdEntities::typesOf($shop);

        if (in_array('AggregateOffer', $types, strict: true)) {
            return PriceNormalizer::fromMixed($shop['lowPrice'] ?? null);
        }

        $normalized = PriceNormalizer::fromMixed($shop['price'] ?? null);
        if ($normalized !== null) {
            return $normalized;
        }

        // `priceSpecification` may be a single object or a list of
        // (Unit)PriceSpecification entries — pick the first usable price.
        $spec = self::firstPriceSpec($shop['priceSpecification'] ?? null);
        if ($spec !== null) {
            return PriceNormalizer::fromMixed($spec['price'] ?? null);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function firstPriceSpec(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['@type']) || isset($value['price'])) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (is_array($entry) && (isset($entry['@type']) || isset($entry['price']))) {
                    /** @var array<string, mixed> $entry */
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $shop
     */
    private static function extractCurrency(array $shop): ?string
    {
        $currency = JsonLdEntities::nonEmptyString($shop['priceCurrency'] ?? null);
        if ($currency !== null) {
            return $currency;
        }

        $spec = self::firstPriceSpec($shop['priceSpecification'] ?? null);
        if ($spec !== null) {
            return JsonLdEntities::nonEmptyString($spec['priceCurrency'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $shop
     */
    private static function extractInStock(array $shop): bool
    {
        $availability = $shop['availability'] ?? null;
        if (! is_string($availability)) {
            return true;
        }

        $availability = strtolower($availability);

        return array_all(['/outofstock', '/discontinued', '/soldout', 'outofstock', 'discontinued', 'soldout'], fn (string $negative): bool => $availability !== $negative && ! str_ends_with($availability, $negative));
    }
}
