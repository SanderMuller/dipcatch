<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for ulta.com.
 *
 * The CeraVe 1.8 oz PDP sampled on 2026-09-11 publishes a schema.org Offer
 * at 6.99 USD matching the visible `.pal-c-Price--PDP` amount and the
 * `sku` query string. The CSS fallback reads that PDP price node.
 */
final readonly class UltaAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'ulta';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'ulta.com' => 'USD',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = self::salesPrice($crawler);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'ulta-css'],
        );
    }

    private static function salesPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.pal-c-Price--PDP .pal-c-Price__priceContainer')->first();
        if ($node->count() === 0) {
            return null;
        }

        return PriceNormalizer::fromMixed(trim($node->text('')));
    }

    private static function title(Crawler $crawler): string
    {
        return PageMarkup::meta($crawler, 'meta[property="og:title"]')
            ?? PageMarkup::element($crawler, 'h1')
            ?? 'Ulta product';
    }

    private static function ogImage(Crawler $crawler): ?string
    {
        return PageMarkup::ogImage($crawler);
    }
}
