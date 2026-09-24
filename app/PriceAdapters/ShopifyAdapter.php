<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Enums\VariantResolution;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Shopify pages, read from the variant list every one of them carries (see
 * {@see ShopifyProduct}).
 *
 * It runs after the JSON-LD reader and before OpenGraph. A theme with no
 * product JSON-LD left a three-flavour page to OpenGraph, which reads one
 * product-level price and cannot know other flavours exist, so the default
 * flavour was tracked under whatever name the caller gave it. Here the page
 * sells what its list says: one variant is read as the only one, a variant the
 * URL or the caller names is read as that one, and several with none named
 * are answered with a chooser. A recheck of a shop saved before this reader
 * existed reads the page default instead, as OpenGraph did, so a shop that
 * had a price does not start failing.
 */
final readonly class ShopifyAdapter implements ShopAdapter
{
    public function key(): string
    {
        return 'shopify';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $product = ShopifyProduct::from($html);

        if ($product === null) {
            return ExtractionResult::skip();
        }

        if (! $product->isComplete()) {
            return ExtractionResult::failed('shopify_variant_unreadable');
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $currency = $product->currency
            ?? PageMarkup::meta($crawler, 'meta[property="og:price:currency"]');

        if ($currency === null) {
            return ExtractionResult::failed('shopify_no_currency');
        }

        $currency = strtoupper($currency);
        $variantKey = $context?->variantKey;
        $urlVariantId = ShopifyProduct::variantIdIn($url);

        // A URL naming a variant the page no longer lists named something:
        // it never falls through to the only variant or the page default,
        // which would price one variant under another's link.
        [$variant, $resolution] = match (true) {
            $variantKey !== null => [ShopifyProduct::keyIsForPage($variantKey, $url) ? $product->variantFor($variantKey) : null, VariantResolution::VariantKey],
            $urlVariantId !== null => [$product->variantFor($urlVariantId), VariantResolution::Url],
            $product->count() === 1 => [$product->variants[0], VariantResolution::OnlyVariant],
            $context?->acceptPageDefault === true => [$product->defaultVariant(), VariantResolution::PageDefault],
            default => [null, null],
        };

        if ($variant === null || $resolution === null) {
            return ExtractionResult::ambiguous(
                self::candidates($product, $url, $currency),
                unmatchedVariantKey: $variantKey ?? ($urlVariantId === null ? null : $url),
            );
        }

        $pageTitle = PageMarkup::meta($crawler, 'meta[property="og:title"]');
        $inStock = $product->available[$variant['id']] ?? null;

        $snapshot = new ShopSnapshot(
            // A picked variant carries its own name — "Bar - Peanut Caramel" —
            // so the tracked shop says which flavour it is. A lone variant
            // keeps the product's name without a "Default Title" suffix.
            title: $resolution === VariantResolution::OnlyVariant ? ($pageTitle ?? $variant['name']) : $variant['name'],
            imageUrl: PageMarkup::meta($crawler, 'meta[property="og:image:secure_url"]')
                ?? PageMarkup::meta($crawler, 'meta[property="og:image"]'),
            price: ShopifyProduct::decimal($variant['price']),
            currency: $currency,
            inStock: $inStock,
            raw: ['source' => 'shopify', 'variant_id' => $variant['id']],
            stockSignal: $inStock === null ? null : 'shopify:available=' . ($inStock ? 'true' : 'false'),
        );

        return ExtractionResult::success($snapshot->withVariants($product->count(), $resolution));
    }

    /**
     * Each variant keyed by its own URL, so a pick stores the link that opens
     * that variant — and a recheck of that URL names it.
     *
     * @return list<VariantCandidate>
     */
    private static function candidates(ShopifyProduct $product, string $url, string $currency): array
    {
        return array_map(static fn (array $variant): VariantCandidate => new VariantCandidate(
            key: ShopifyProduct::variantUrl($url, $variant['id']),
            title: $variant['title'],
            price: ShopifyProduct::decimal($variant['price']),
            currency: $currency,
            inStock: $product->available[$variant['id']] ?? true,
        ), $product->variants);
    }
}
