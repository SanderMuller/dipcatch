<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for petsplace.nl. Magento JSON-LD carries a usable
 * Offer on most product pages; the CSS fallback reads the `finalPrice`
 * amount because the page also stamps `data-price-amount` on struck-through
 * list prices.
 */
final readonly class PetsPlaceAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'petsplace';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'petsplace.nl' => 'EUR',
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
            raw: ['source' => 'petsplace-css'],
        );
    }

    private function extractPrice(Crawler $crawler): ?string
    {
        $final = $crawler->filter('[data-price-type="finalPrice"]')->first();
        if ($final->count() === 0) {
            return null;
        }

        $amount = $final->attr('data-price-amount');

        return PriceNormalizer::fromMixed(is_string($amount) ? $amount : null);
    }

    private function extractTitle(Crawler $crawler): string
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

        return 'Pets Place product';
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
