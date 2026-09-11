<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for etos.nl (Salesforce Commerce Cloud).
 *
 * Product pages publish a schema.org Offer whose price matched the visible
 * shelf price on the CeraVe PDPs sampled on 2026-09-11 (23.15 and 18.85).
 * The CSS fallback reads `price__item--sales`, the amount the storefront
 * paints as the current price, not a struck-through advice price.
 */
final readonly class EtosAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'etos';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'etos.nl' => 'EUR',
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
            raw: ['source' => 'etos-css'],
        );
    }

    private static function salesPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.price__item--sales .price__value')->first();
        if ($node->count() === 0) {
            return null;
        }

        $content = $node->attr('content');

        return PriceNormalizer::fromMixed(is_string($content) ? $content : trim($node->text('')));
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

        return 'Etos product';
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
}
