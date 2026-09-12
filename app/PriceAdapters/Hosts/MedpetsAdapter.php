<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for medpets.nl / .be. Product pages publish schema.org
 * JSON-LD with one Offer per SKU, so the JSON-LD pass handles variants. The
 * CSS fallback reads `data-product-price` on the free-shipping label, the
 * amount the shop's own front-end uses when JSON-LD is missing.
 */
final readonly class MedpetsAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'medpets';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'medpets.nl' => 'EUR',
            'medpets.be' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = $this->extractPrice($crawler);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: $this->extractTitle($crawler),
            imageUrl: $this->extractImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'medpets-css'],
        );
    }

    private function extractPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[data-product-price]')->first();
        if ($node->count() === 0) {
            return null;
        }

        $amount = $node->attr('data-product-price');

        return PriceNormalizer::fromMixed(is_string($amount) ? $amount : null);
    }

    private function extractTitle(Crawler $crawler): string
    {
        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = trim($h1->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        $og = $crawler->filter('meta[property="og:title"]')->first();
        if ($og->count() > 0) {
            $content = $og->attr('content');
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
        }

        return 'Medpets product';
    }

    private function extractImage(Crawler $crawler): ?string
    {
        $og = $crawler->filter('meta[property="og:image"]')->first();
        if ($og->count() === 0) {
            return null;
        }

        $content = $og->attr('content');

        return is_string($content) && $content !== '' ? $content : null;
    }
}
