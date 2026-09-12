<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Gtin;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for dierapotheker.nl (Shopware 6).
 *
 * The page's only `itemprop="price"` sits inside the "vanaf 2 stuks" row of
 * the quantity-discount table, so the microdata adapter tracked 51.90 for a
 * product that costs 52.95 to buy one of (verified 2026-09-02). The single
 * price is on `#product-data`, the element the shop's own analytics reads.
 *
 * The pack size appears nowhere in the product markup — not in the title,
 * not in the microdata — so it comes from the `view_item` analytics payload,
 * the one place the page states which variant it shows.
 */
final readonly class DierapothekerAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'dierapotheker';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'dierapotheker.nl')) {
            return ExtractionResult::skip();
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $data = $crawler->filter('#product-data')->first();

        if ($data->count() === 0) {
            return ExtractionResult::failed('dierapotheker_no_product_data');
        }

        $productId = HostUrl::lastNumericSegment($url);

        // The element states which article it describes; a redirect or a
        // stale response would otherwise price a different product.
        if ($productId === null || $data->attr('data-product-number') !== $productId) {
            return ExtractionResult::failed('dierapotheker_product_mismatch');
        }

        $price = PriceNormalizer::fromMixed($data->attr('data-price'));

        if ($price === null) {
            return ExtractionResult::failed('dierapotheker_no_price');
        }

        $currency = $data->attr('data-currency');
        $availability = $data->attr('data-availability');
        $name = $data->attr('data-name');
        $image = $data->attr('data-image');

        return ExtractionResult::success(new ShopSnapshot(
            title: is_string($name) && trim($name) !== '' ? trim($name) : 'Unknown',
            imageUrl: is_string($image) && $image !== '' ? $image : null,
            price: $price,
            // A Dutch-only shop; every page sampled states EUR.
            currency: is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR',
            inStock: ! is_string($availability) || self::isBuyable($availability),
            raw: ['source' => 'dierapotheker'],
            packSize: self::packSize($html),
            packSizeAuthoritative: false,
            gtin: self::gtin($crawler),
            gtinAuthoritative: true,
        ));
    }

    /** Schema.org availability values that mean the shopper can buy it now. */
    private static function isBuyable(string $availability): bool
    {
        return str_contains($availability, 'InStock') || str_contains($availability, 'LimitedAvailability');
    }

    /**
     * The variant name from the `view_item` analytics event — "Navulling
     * 3 x 48 ml". Nothing else on the page states the size, and a size read
     * from the wrong event would be another product's, so only the
     * `view_item` payload counts.
     */
    private static function packSize(string $html): ?string
    {
        // The pattern may not cross into the next event: the page pushes a
        // list event carrying other products' variants right after this one.
        if (preg_match('/"event"\s*:\s*"view_item"(?:(?!"event"\s*:).)*?"item_variant"\s*:\s*"([^"]+)"/s', $html, $m) !== 1) {
            return null;
        }

        $variant = trim(stripcslashes($m[1]));

        return $variant === '' ? null : $variant;
    }

    private static function gtin(Crawler $crawler): ?string
    {
        $meta = $crawler->filter('meta[itemprop="gtin13"]')->first();

        return $meta->count() === 0 ? null : Gtin::normalize($meta->attr('content'));
    }
}
