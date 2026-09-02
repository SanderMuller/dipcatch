<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NextData;

/**
 * Host-specific adapter for aldi.nl. The pages carry no JSON-LD, no price
 * meta tags and no price in the served markup: it is a Next.js app whose
 * product record sits in the `__NEXT_DATA__` payload, under a nested JSON
 * string of API responses keyed `PRODUCT_DETAIL_GET` (observed 2026-09-02).
 *
 * Every price carries a validity window — Aldi prices the whole assortment
 * in campaign periods — and the payload keeps the record after the window
 * closes, so an expired price is refused rather than reported as current.
 */
final readonly class AldiAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'aldi';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'aldi.nl')) {
            return ExtractionResult::skip();
        }

        $slug = self::slug($url);

        if ($slug === null) {
            // A category or search URL names no article, while the payload
            // still holds whichever products that page listed.
            return ExtractionResult::failed('aldi_no_product_slug');
        }

        $data = NextData::decode($html);

        if ($data === null) {
            return ExtractionResult::failed('aldi_no_payload');
        }

        $product = self::product($data, $slug);

        if ($product === null) {
            return ExtractionResult::failed('aldi_no_product');
        }

        $price = AldiOffer::price($product);

        if ($price === null) {
            return ExtractionResult::failed('aldi_no_current_price');
        }

        $salesUnit = $product['salesUnit'] ?? null;

        return ExtractionResult::success(new ShopSnapshot(
            title: self::title($product),
            imageUrl: self::imageUrl($product),
            price: $price,
            currency: 'EUR',
            // A page only exists for a listed product, so an absent
            // flag is not evidence that it sold out.
            inStock: ($product['isAvailable'] ?? true) !== false,
            raw: ['source' => 'aldi'],
            packSize: is_string($salesUnit) && trim($salesUnit) !== '' ? $salesUnit : null,
            packSizeAuthoritative: true,
            // The price passed its window check, so that window is the one
            // it belongs to.
            promotionWindow: AldiOffer::window($product),
            promotionWindowAuthoritative: true,
        ));
    }

    /**
     * The product slug the URL names: `/product/granola-91244024.html` →
     * `granola-91244024`. It is what the payload's own `productSlug` is
     * matched against, so a page describing another article is refused.
     */
    private static function slug(string $url): ?string
    {
        $segment = HostUrl::lastSegment($url);

        if ($segment === null) {
            return null;
        }

        $slug = preg_replace('/\.html?$/i', '', $segment) ?? $segment;

        return $slug === '' ? null : $slug;
    }

    /**
     * The `PRODUCT_DETAIL_GET` record whose slug matches the URL. The
     * payload is a list of `[key, {req, res}]` pairs serialized as a JSON
     * string inside the page props.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>|null
     */
    private static function product(array $data, string $slug): ?array
    {
        $apiData = NextData::value($data, 'props.pageProps.apiData');

        if (! is_string($apiData)) {
            return null;
        }

        $calls = json_decode($apiData, true);

        if (! is_array($calls)) {
            return null;
        }

        foreach ($calls as $call) {
            if (! is_array($call) || ($call[0] ?? null) !== 'PRODUCT_DETAIL_GET') {
                continue;
            }

            $products = is_array($call[1] ?? null) ? NextData::value($call[1], 'res.products') : null;

            if (! is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                if (is_array($product) && ($product['productSlug'] ?? null) === $slug) {
                    return $product;
                }
            }
        }

        return null;
    }

    /**
     * Brand and name are separate fields; the page's own title joins them.
     *
     * @param  array<mixed, mixed>  $product
     */
    private static function title(array $product): string
    {
        $name = is_string($product['name'] ?? null) ? trim($product['name']) : '';
        $brand = is_string($product['brandName'] ?? null) ? trim($product['brandName']) : '';

        $title = trim($brand . ' ' . $name);

        return $title === '' ? 'Unknown' : $title;
    }

    /**
     * The `primary` asset. The gallery assets are the campaign's other
     * variants — a granola page ships three flavours — so falling back to
     * one would show a different product's photo.
     *
     * @param  array<mixed, mixed>  $product
     */
    private static function imageUrl(array $product): ?string
    {
        $assets = $product['assets'] ?? null;

        if (! is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['type'] ?? null) === 'primary' && is_string($asset['url'] ?? null)) {
                return $asset['url'];
            }
        }

        return null;
    }
}
