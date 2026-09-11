<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for theordinary.com (Demandware / Salesforce).
 *
 * The NL product page sampled on 2026-09-11 (Niacinamide 10% + Zinc 1%)
 * publishes a single schema.org Offer at 6.00 EUR, matching the visible
 * `.sales .value`. The CSS fallback reads that sales span, not a
 * struck-through list price.
 */
final readonly class OrdinaryAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'ordinary';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'theordinary.com' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $node = $crawler->filter('.product-price .sales .value')->first();
        if ($node->count() === 0) {
            return null;
        }

        $content = $node->attr('content');
        $text = trim($node->text(''));
        $price = PriceNormalizer::fromMixed(is_string($content) ? $content : $text);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: self::currencyFromPriceText($text, $currency),
            inStock: true,
            raw: ['source' => 'ordinary-css'],
        );
    }

    private static function currencyFromPriceText(string $text, string $fallback): string
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

        return $fallback;
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

        return 'The Ordinary product';
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
