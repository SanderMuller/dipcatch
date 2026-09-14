<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for walmart.com.
 *
 * The CeraVe 16 oz PDP sampled on 2026-09-11 has no product JSON-LD (only a
 * WebPage blob). The visible hero price is `$15.97` on
 * `[data-seo-id="hero-price"]`, with `itemProp="priceCurrency"` USD next to
 * it. Multipack tiles sit in other nodes and must not be read first.
 */
final readonly class WalmartAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'walmart';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'walmart.com' => 'USD',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = self::heroPrice($crawler);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'walmart-css'],
        );
    }

    private static function heroPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[data-seo-id="hero-price"]')->first();
        if ($node->count() === 0) {
            return null;
        }

        return PriceNormalizer::fromMixed(trim($node->text('')));
    }

    private static function title(Crawler $crawler): string
    {
        return HostPage::text($crawler, 'meta[property="og:title"]', 'h1') ?? 'Walmart product';
    }

    private static function ogImage(Crawler $crawler): ?string
    {
        return HostPage::ogImage($crawler);
    }
}
