<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\DomCrawler\Crawler;

/**
 * OpenGraph product metadata: `og:price:amount`, `og:price:currency`,
 * `og:title`, `og:image`, `og:availability`. Skips when no og:price:amount
 * meta tag is present.
 */
final readonly class OpenGraphAdapter implements ShopAdapter
{
    public function key(): string
    {
        return 'og';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $crawler = self::crawler($html);

        $amount = self::meta($crawler, 'og:price:amount')
            ?? self::meta($crawler, 'product:price:amount');

        if ($amount === null) {
            return ExtractionResult::skip();
        }

        $price = PriceNormalizer::fromMixed($amount);
        if ($price === null) {
            return ExtractionResult::failed('og_invalid_price');
        }

        $currency = self::meta($crawler, 'og:price:currency')
            ?? self::meta($crawler, 'product:price:currency');

        if ($currency === null) {
            return ExtractionResult::failed('og_no_currency');
        }

        $title = self::meta($crawler, 'og:title') ?? 'Unknown';
        $image = self::meta($crawler, 'og:image');
        $availability = self::meta($crawler, 'og:availability')
            ?? self::meta($crawler, 'product:availability');

        return ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: $image,
            price: $price,
            currency: strtoupper($currency),
            inStock: self::availabilityInStock($availability),
            raw: ['source' => 'og'],
        ));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    private static function meta(Crawler $crawler, string $property): ?string
    {
        $node = $crawler->filter('meta[property="' . $property . '"]')->first();
        if ($node->count() === 0) {
            $node = $crawler->filter('meta[name="' . $property . '"]')->first();
        }
        if ($node->count() === 0) {
            return null;
        }

        $content = $node->attr('content');

        return is_string($content) && $content !== '' ? $content : null;
    }

    private static function availabilityInStock(?string $availability): bool
    {
        if (! is_string($availability)) {
            return true;
        }

        $availability = strtolower($availability);

        if (str_contains($availability, 'out of stock') || str_contains($availability, 'outofstock') || str_contains($availability, 'oos')) {
            return false;
        }

        return true;
    }
}
