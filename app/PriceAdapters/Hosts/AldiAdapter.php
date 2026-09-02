<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NextData;
use Carbon\CarbonImmutable;

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

        $price = self::currentPrice($product);

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
     * The price, but only while its window is open. The payload keeps the
     * last campaign's price after it ends, so reporting it would present an
     * expired price as today's.
     *
     * @param  array<mixed, mixed>  $product
     */
    private static function currentPrice(array $product): ?string
    {
        $current = $product['currentPrice'] ?? null;

        if (! is_array($current)) {
            return null;
        }

        $from = self::bound($current, 'validFrom');
        $until = self::bound($current, 'validUntil');
        $now = CarbonImmutable::now();

        // Each bound is judged on its own: a record carrying only an end
        // date still says when the price stops being current. A bound the
        // payload states but this adapter cannot read may be hiding an
        // expiry, so it refuses the price instead of assuming the window
        // is open.
        if ($from === false || $until === false) {
            return null;
        }

        if ($from instanceof CarbonImmutable && $now->lessThan($from)) {
            return null;
        }

        if ($until instanceof CarbonImmutable && $now->greaterThan($until)) {
            return null;
        }

        return PriceNormalizer::fromMixed($current['priceValue'] ?? null);
    }

    /**
     * A validity bound: the parsed instant, null when the payload omits it,
     * or false when it states one this adapter cannot read.
     *
     * @param  array<mixed, mixed>  $price
     */
    private static function bound(array $price, string $key): CarbonImmutable|false|null
    {
        $value = $price[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }

        return is_string($value) && ctype_digit($value)
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : false;
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
