<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

final readonly class BolAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'bol';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'bol.com' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $priceNode = $crawler->filter('[data-test="price"]')->first();
        if ($priceNode->count() === 0) {
            $priceNode = $crawler->filter('.promo-price')->first();
        }
        if ($priceNode->count() === 0) {
            return null;
        }

        $price = PriceNormalizer::fromMixed(trim($priceNode->text('')));
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: PageMarkup::element($crawler, 'h1.product-title') ?? 'Bol product',
            imageUrl: PageMarkup::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'bol-css'],
        );
    }
}
