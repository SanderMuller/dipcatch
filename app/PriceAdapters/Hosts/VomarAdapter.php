<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Gtin;
use App\Support\UrlNormalizer;
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

    public function key(): string
    {
        return 'vomar';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! self::handles($url)) {
            return ExtractionResult::skip();
        }

        $details = self::productDetails($html);

        if ($details === null) {
            return ExtractionResult::failed('vomar_no_product');
        }

        $price = PriceNormalizer::fromMixed(self::literal($details, 'price'));

        if ($price === null) {
            return ExtractionResult::failed('vomar_no_price');
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: self::literal($details, 'description') ?? self::heading($html) ?? 'Unknown',
            imageUrl: self::imageUrl($details),
            price: $price,
            currency: 'EUR',
            // `inWebshop` is minified to an identifier, so its value cannot
            // be read reliably; the shop only serves pages for products it
            // lists, and a delisted id 500s instead.
            inStock: true,
            raw: ['source' => 'vomar'],
            packSize: self::packSize($details),
            packSizeAuthoritative: true,
            gtin: Gtin::normalize(self::literal($details, 'primaryEan')),
            gtinAuthoritative: true,
        ));
    }

    public static function handles(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? UrlNormalizer::normalizeHost($host) : '';

        return $host === 'vomar.nl' || str_ends_with($host, '.vomar.nl');
    }

    private static function productDetails(string $html): ?string
    {
        if (preg_match('/productDetails:\{(.{0,4000})/s', $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * A quoted string or a bare number. An identifier (a hoisted value)
     * yields null — a wrong value is worse than a missing one.
     */
    private static function literal(string $details, string $field): ?string
    {
        if (preg_match('/\b' . preg_quote($field, '/') . ':(?:"((?:[^"\\\\]|\\\\.)*)"|(\d+(?:\.\d+)?))/', $details, $m) !== 1) {
            return null;
        }

        $value = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

        return $value === '' ? null : stripcslashes($value);
    }

    private static function packSize(string $details): ?string
    {
        $contents = self::literal($details, 'contents');
        $unit = self::literal($details, 'unit');

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
