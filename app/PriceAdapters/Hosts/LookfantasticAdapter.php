<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for lookfantastic.com and cultbeauty.com (THG).
 *
 * Product pages publish a ProductGroup with one Offer per pack and no
 * variant URL, so JSON-LD alone is ambiguous. The last numeric path
 * segment is the selected SKU and is passed as the JSON-LD variant key.
 * Cult Beauty geo-prices; CSS `#product-price` takes £ / € / $ from the
 * painted text rather than a host→currency map.
 */
final readonly class LookfantasticAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'lookfantastic';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'lookfantastic.com') && ! HostUrl::matches($url, 'cultbeauty.com')) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, self::withUrlSku($url, $context));
        if ($result->isSuccess() || $result->isAmbiguous()) {
            return $result;
        }

        $snapshot = self::fromCss($html, $context?->fallbackCurrency);
        if ($snapshot !== null) {
            return ExtractionResult::success($snapshot);
        }

        return ExtractionResult::failed('lookfantastic_extraction_failed');
    }

    private static function withUrlSku(string $url, ?AdapterContext $context): ?AdapterContext
    {
        if ($context?->variantKey !== null) {
            return $context;
        }

        $sku = HostUrl::lastNumericSegment($url);
        if ($sku === null) {
            return $context;
        }

        if ($context === null) {
            return new AdapterContext(variantKey: $sku);
        }

        return $context->withVariantKey($sku);
    }

    private static function fromCss(string $html, ?string $fallbackCurrency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $node = $crawler->filter('#product-price')->first();
        if ($node->count() === 0) {
            return null;
        }

        $text = trim($node->text(''));
        $price = PriceNormalizer::fromMixed($text);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: self::currencyFromPriceText($text, $fallbackCurrency),
            inStock: true,
            raw: ['source' => 'lookfantastic-css'],
        );
    }

    private static function title(Crawler $crawler): string
    {
        $og = $crawler->filter('meta[property="og:title"]')->first();
        if ($og->count() > 0) {
            $content = $og->attr('content');
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
        }

        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = trim($h1->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        return 'Lookfantastic product';
    }

    private static function ogImage(Crawler $crawler): ?string
    {
        $og = $crawler->filter('meta[property="og:image"]')->first();
        if ($og->count() === 0) {
            return null;
        }

        $content = $og->attr('content');

        return is_string($content) && $content !== '' ? $content : null;
    }

    private static function currencyFromPriceText(string $text, ?string $fallback): string
    {
        if (str_contains($text, '£')) {
            return 'GBP';
        }

        if (str_contains($text, '€')) {
            return 'EUR';
        }

        if (str_contains($text, '$')) {
            return 'USD';
        }

        return $fallback ?? 'GBP';
    }
}
