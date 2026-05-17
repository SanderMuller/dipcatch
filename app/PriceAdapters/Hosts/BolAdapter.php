<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

final readonly class BolAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'bol';
    }

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

        $titleNode = $crawler->filter('h1.product-title')->first();
        $title = $titleNode->count() > 0 ? trim($titleNode->text('')) : 'Bol product';

        $imageNode = $crawler->filter('meta[property="og:image"]')->first();
        $image = $imageNode->count() > 0 ? $imageNode->attr('content') : null;

        return new ShopSnapshot(
            title: $title,
            imageUrl: $image,
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'bol-css'],
        );
    }
}
