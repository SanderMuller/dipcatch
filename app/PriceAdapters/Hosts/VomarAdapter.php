<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Gtin;
use App\Support\JsObjectLiteral;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for vomar.nl. The page carries no JSON-LD and no
 * price meta tags: it is a Nuxt 2 app whose server-rendered state sits in a
 * minified `window.__NUXT__` IIFE, so the product fields are read out of the
 * `productDetails:{…}` object literal with targeted patterns rather than by
 * decoding JSON (observed 2026-09-01).
 *
 * The minifier hoists repeated values into IIFE parameters, so a field can
 * be an identifier instead of a literal. Prices were literals in every page
 * sampled; when one is not, the adapter fails rather than guessing. The
 * title falls back to the page's `<h1>`, which carries the product name.
 */
final readonly class VomarAdapter implements HostSpecificAdapter, ShopAdapter
{
    private const string IMAGE_BASE = 'https://d3vricquk1sjgf.cloudfront.net/';

    /** Scan cap for the product object; a real one is a few kB. */
    private const int MAX_OBJECT_BYTES = 20_000;

    public function key(): string
    {
        return 'vomar';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'vomar.nl')) {
            return ExtractionResult::skip();
        }

        $details = self::productDetails($html);

        if ($details === null) {
            return ExtractionResult::failed('vomar_no_product');
        }

        // Only the object's own fields: a nested promotion or unit-price
        // object carries a `price` too, and reading that as the shelf price
        // would raise a price-drop alert for a discount that is not one.
        $fields = JsObjectLiteral::fields($details);

        $productId = HostUrl::lastNumericSegment($url);

        if ($productId === null) {
            // A category or search URL names no article, and the state holds
            // whichever product the page happened to render first.
            return ExtractionResult::failed('vomar_no_product_id');
        }

        // The state must say it describes that article. A hoisted (non
        // literal) articleNumber proves nothing, so it is refused too.
        if (JsObjectLiteral::literal($fields, 'articleNumber') !== $productId) {
            return ExtractionResult::failed('vomar_product_mismatch');
        }

        $price = PriceNormalizer::fromMixed(JsObjectLiteral::literal($fields, 'price'));

        if ($price === null) {
            return ExtractionResult::failed('vomar_no_price');
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: JsObjectLiteral::literal($fields, 'description') ?? self::heading($html) ?? 'Unknown',
            imageUrl: self::imageUrl($details),
            price: $price,
            currency: 'EUR',
            // `inWebshop` is minified to an identifier, so its value cannot
            // be read reliably; the shop only serves pages for products it
            // lists, and a delisted id 500s instead.
            inStock: true,
            raw: ['source' => 'vomar'],
            packSize: self::packSize($fields),
            packSizeAuthoritative: true,
            gtin: Gtin::normalize(JsObjectLiteral::literal($fields, 'primaryEan')),
            gtinAuthoritative: true,
        ));
    }

    /**
     * The `productDetails` object, cut at its own closing brace rather than
     * at a fixed width: a window that overruns the object would read a
     * neighbouring product's fields as if they belonged to this one.
     */
    private static function productDetails(string $html): ?string
    {
        return JsObjectLiteral::after($html, 'productDetails:{', self::MAX_OBJECT_BYTES);
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function packSize(array $fields): ?string
    {
        $contents = JsObjectLiteral::literal($fields, 'contents');
        $unit = JsObjectLiteral::literal($fields, 'unit');

        if ($contents === null || $unit === null) {
            return null;
        }

        return $contents . ' ' . $unit;
    }

    private static function imageUrl(string $details): ?string
    {
        if (preg_match('/fileName:"([^"]+)"/', $details, $m) !== 1) {
            return null;
        }

        // The payload escapes slashes as /.
        $path = str_replace('\\u002F', '/', $m[1]);

        return self::IMAGE_BASE . ltrim($path, '/');
    }

    private static function heading(string $html): ?string
    {
        $heading = new Crawler($html)->filter('h1')->first();

        if ($heading->count() === 0) {
            return null;
        }

        $text = trim($heading->text(''));

        return $text === '' ? null : $text;
    }
}
