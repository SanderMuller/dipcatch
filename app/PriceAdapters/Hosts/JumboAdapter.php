<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for jumbo.com. Jumbo ships JSON-LD (an
 * AggregateOffer whose high/low equal the shown price), so the happy path
 * delegates there. The CSS fallback reads the server-rendered price
 * component: `[data-testid="product-price"]` contains a screenreader div
 * ("Prijs: € 7,59") plus `.whole`/`.fractional` spans.
 *
 * Note: Jumbo multi-buy promotions ("1+1 gratis", "2 voor X") do not lower
 * the unit price on the page — only straight price cuts show up in either
 * JSON-LD or the price component.
 */
final readonly class JumboAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'jumbo';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'jumbo.com' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = self::priceFromComponent($crawler);
        if ($price === null) {
            return null;
        }

        $titleNode = $crawler->filter('meta[property="og:title"]')->first();
        $title = $titleNode->count() > 0 ? trim((string) $titleNode->attr('content')) : '';
        if ($title === '') {
            $h1 = $crawler->filter('h1')->first();
            $title = $h1->count() > 0 ? trim($h1->text('')) : 'Jumbo product';
        }

        $imageNode = $crawler->filter('meta[property="og:image"]')->first();
        $image = $imageNode->count() > 0 ? $imageNode->attr('content') : null;

        return new ShopSnapshot(
            title: $title,
            imageUrl: $image,
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'jumbo-css'],
        );
    }

    private static function priceFromComponent(Crawler $crawler): ?string
    {
        $component = $crawler->filter('[data-testid="product-price"]')->first();
        if ($component->count() === 0) {
            return null;
        }

        $screenreader = $component->filter('.current-price .screenreader-only')->first();
        if ($screenreader->count() > 0) {
            $price = PriceNormalizer::fromMixed(trim($screenreader->text('')));
            if ($price !== null) {
                return $price;
            }
        }

        // Screenreader div missing or unparseable — rebuild from the visual spans.
        $whole = $component->filter('.current-price .whole')->first();
        $fractional = $component->filter('.current-price .fractional')->first();
        if ($whole->count() === 0 || $fractional->count() === 0) {
            return null;
        }

        return PriceNormalizer::fromMixed(trim($whole->text('')) . ',' . trim($fractional->text('')));
    }
}
