<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Enums\VariantResolution;
use App\Support\Gtin;
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

        // Some Shopify themes put only the selected variant in their JSON-LD.
        // Read from here, a three-flavour page would say it sells one. The
        // page's own variant list is the fuller account, so defer to it.
        $shopifyVariants = ShopifyProduct::variantCount($html);

        if ($shopifyVariants !== null && $shopifyVariants > 1 && $state->variantsSeen < $shopifyVariants) {
            return ExtractionResult::skip();
        }

        // Variant ambiguity wins over a weak fallback: when the page lists
        // multiple variants and the caller didn't pin one via context, ask
        // the user instead of silently guessing. A variant the URL itself
        // names is not a guess, so a match ends the question — the variants
        // walked past on the way to it are not open options.
        if (! $state->identified() && $context?->variantKey === null && count($state->variants) > 1) {
            return ExtractionResult::ambiguous($state->variants);
        }

        // A key that matched nothing must never fall through to whatever the
        // URL happens to name: the caller asked for one variant and would be
        // handed the price of another without being told.
        $variantKey = $context?->variantKey;

        if ($variantKey !== null && ! $state->keyMatched) {
            return $state->variants === []
                ? ExtractionResult::failed('variant_key_no_match')
                : ExtractionResult::ambiguous($state->variants, unmatchedVariantKey: $variantKey);
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

        return $this->buildSnapshot($product, $shop, $state, $variantKey);
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
                $searcher->consider($entity, $url, $context, $state);
            }

            if ($state->shop === null && $state->product !== null && isset($state->product['offers'])) {
                $state->shop = JsonLdEntities::pickOfferFromProduct($state->product['offers']);
            }
        }

        $searcher->finish($state);

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
    private function buildSnapshot(?array $product, array $shop, JsonLdSearchState $state, ?string $variantKey): ExtractionResult
    {
        $price = JsonLdOfferPrice::price($shop);
        if ($price === null) {
            return ExtractionResult::failed('jsonld_no_price');
        }

        $currency = JsonLdOfferPrice::currency($shop);
        if ($currency === null) {
            return ExtractionResult::failed('jsonld_no_currency');
        }

        $title = JsonLdEntities::nonEmptyString($product['name'] ?? null)
            ?? JsonLdEntities::nonEmptyString($shop['name'] ?? null)
            ?? 'Unknown';
        $imageUrl = JsonLdEntities::firstImageUrl($product['image'] ?? null)
            ?? JsonLdEntities::firstImageUrl($shop['image'] ?? null);

        $packSize = UnitPriceSize::from($shop, $price);
        [$inStock, $stockSignal] = StockAvailability::read($shop['availability'] ?? null);

        $result = ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: $imageUrl,
            price: $price,
            currency: strtoupper($currency),
            inStock: $inStock,
            raw: ['offer' => $shop],
            packSize: $packSize,
            // Only when the offer stated one: otherwise the title fallback
            // must stay available.
            packSizeAuthoritative: $packSize !== null,
            gtin: Gtin::fromEntities([$product, $shop]),
            gtinAuthoritative: true,
            promotionWindow: OfferValidity::windowFrom($shop),
            // The offer supplied the price, so it speaks for the promotion
            // too: an offer that no longer states an end date has none.
            promotionWindowAuthoritative: true,
            stockSignal: $stockSignal,
        ));

        return self::withVariantCount($result, $state, $variantKey);
    }

    /**
     * Say what the page turned out to sell, and how this one was picked.
     *
     * Only when the page listed variants at all. A plain Product entity says
     * nothing about variants, and answering "one" there would state a fact
     * this reader did not read.
     */
    private static function withVariantCount(ExtractionResult $result, JsonLdSearchState $state, ?string $variantKey): ExtractionResult
    {
        $snapshot = $result->snapshot;
        $count = $state->variantsSeen;

        if ($count === 0 || ! $snapshot instanceof ShopSnapshot) {
            return $result;
        }

        // Reaching here with more than one variant means the request named
        // one: `extract()` answers an unnamed choice with the chooser before
        // any snapshot is built. So there is no "took the default" answer to
        // give, and the two named cases plus the single-variant one are
        // exhaustive.
        $resolution = match (true) {
            $variantKey !== null && $state->keyMatched => VariantResolution::VariantKey,
            $count === 1 => VariantResolution::OnlyVariant,
            default => VariantResolution::Url,
        };

        return ExtractionResult::success($snapshot->withVariants($count, $resolution));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }
}
