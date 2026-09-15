<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PageMarkup;
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
        return PageMarkup::meta($crawler, 'meta[property="og:title"]')
            ?? PageMarkup::element($crawler, 'h1')
            ?? 'Pets Place product';
    }

    private function extractImage(Crawler $crawler): ?string
    {
        return PageMarkup::ogImage($crawler);
    }
}
