<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for zooplus.nl / .de / .com / .co.uk and bitiba.nl
 * / .de etc. Zooplus Group runs the same Next.js template on both brands.
 * Dutch pages expose no JSON-LD price; the UK shop does, so JSON-LD wins
 * there. Elsewhere the price is server-rendered into spans tagged
 * `data-zta="reducedPriceAmount"`. Multi-variant pages render one such span
 * per variant — the *active* one (selected via `?activeVariant=…`) carries a
 * `Variant_activeVariant__<hash>` class on its wrapping price cell.
 */
final readonly class ZooplusAdapter extends HostAdapter
{
    public function key(): string
    {
        return 'zooplus';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        // Zooplus Group runs the same template across country TLDs and the
        // Bitiba sister shops.
        return [
            'zooplus.nl' => 'EUR',
            'zooplus.be' => 'EUR',
            'zooplus.de' => 'EUR',
            'zooplus.fr' => 'EUR',
            'zooplus.it' => 'EUR',
            'zooplus.es' => 'EUR',
            'zooplus.at' => 'EUR',
            'zooplus.ie' => 'EUR',
            'zooplus.pt' => 'EUR',
            'zooplus.fi' => 'EUR',
            'zooplus.lu' => 'EUR',
            'zooplus.com' => 'EUR',
            'zooplus.co.uk' => 'GBP',
            'bitiba.nl' => 'EUR',
            'bitiba.be' => 'EUR',
            'bitiba.de' => 'EUR',
            'bitiba.fr' => 'EUR',
            'bitiba.it' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $priceText = self::activeVariantPrice($crawler) ?? self::firstReducedPrice($crawler);
        if ($priceText === null) {
            return null;
        }

        $price = PriceNormalizer::fromMixed($priceText);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'zooplus-css'],
        );
    }

    /**
     * Picks the price inside the wrapping cell that's marked active. The
     * class name has a build-hash suffix (`Variant_activeVariant__LnO_V`),
     * so we match by `class*="activeVariant"` to survive deploys.
     */
    private static function activeVariantPrice(Crawler $crawler): ?string
    {
        $node = $crawler
            ->filter('div[data-zta="Variant__Price"][class*="activeVariant"] [data-zta="reducedPriceAmount"]')
            ->first();

        if ($node->count() === 0) {
            return null;
        }

        $text = trim($node->text(''));

        return $text === '' ? null : $text;
    }

    private static function firstReducedPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[data-zta="reducedPriceAmount"]')->first();
        if ($node->count() === 0) {
            return null;
        }

        $text = trim($node->text(''));

        return $text === '' ? null : $text;
    }

    private static function title(Crawler $crawler): string
    {
        // The scoped h1 is the product's own; a bare h1 also matches the
        // related-product headings further down the page.
        return HostPage::text($crawler, 'h1[data-zta="ProductTitle__Title"]', 'meta[property="og:title"]')
            ?? 'Zooplus product';
    }

    private static function ogImage(Crawler $crawler): ?string
    {
        return HostPage::ogImage($crawler);
    }
}
