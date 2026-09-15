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
        [$inStock, $stockSignal] = StockAvailability::read($availability);

        return ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: $image,
            price: $price,
            currency: strtoupper($currency),
            inStock: $inStock,
            raw: ['source' => 'og'],
            stockSignal: $stockSignal,
        ));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    /**
     * Open Graph is published under `property`, but enough pages spell it
     * `name` that both are read, in that order.
     */
    private static function meta(Crawler $crawler, string $property): ?string
    {
        return PageMarkup::meta($crawler, 'meta[property="' . $property . '"]')
            ?? PageMarkup::meta($crawler, 'meta[name="' . $property . '"]');
    }
}
