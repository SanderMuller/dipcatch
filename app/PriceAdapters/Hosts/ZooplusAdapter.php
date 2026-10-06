<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\JsonLdOfferPrice;
use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for zooplus.nl / .de / .com / .co.uk and bitiba.nl
 * / .de etc. Zooplus Group runs the same Next.js template on both brands.
 *
 * The JSON-LD states a price, but where a repeat-order or zooclub discount
 * applies it states that member price as the offer's price. The JSON-LD
 * reader refuses it ({@see JsonLdOfferPrice::isMemberPrice()}), and the
 * regular price then comes from the page state, for the variant the URL or
 * the chosen variant key names. Without page state the price is read from
 * the spans tagged `data-zta="reducedPriceAmount"`: multi-variant pages
 * render one per variant, and the *active* one (selected via
 * `?activeVariant=…`) carries a `Variant_activeVariant__<hash>` class on its
 * wrapping price cell.
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

    /**
     * The regular price of the variant the URL or the chosen key names. A
     * chosen key the page does not list reads nothing rather than the
     * price of whichever variant the page shows.
     */
    protected function extractFromPage(string $url, string $html, string $currency, ?AdapterContext $context): ?ShopSnapshot
    {
        $variantKey = $context?->variantKey;
        $state = ZooplusPageState::read($html);
        $variant = $state?->named([$variantKey, $state->pageVariant, ZooplusPageState::queryVariant($url)])
            ?? ($state !== null && count($state->variants) === 1 ? $state->variants[0] : null);
        $price = $variant === null ? null : ZooplusPageState::regularPrice($variant);

        if ($price === null) {
            return $variantKey === null ? $this->extractFromHtml($html, $currency) : null;
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'zooplus-state'],
        );
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

    /**
     * The pack size the page state states for the variant this snapshot
     * priced. The JSON-LD cannot say it: on a sale it pairs the sale price
     * with the regular price's rate, so a 10 kg bag reads as 9 kg.
     */
    protected function refine(ShopSnapshot $snapshot, string $url, string $html, ?AdapterContext $context): ShopSnapshot
    {
        $size = ZooplusPackSize::read($url, $html, $snapshot->price, $context?->variantKey);

        return $size === null ? $snapshot : $snapshot->withPackSize($size);
    }

    private static function title(Crawler $crawler): string
    {
        // Scoped to the product-title component rather than a bare h1.
        return PageMarkup::element($crawler, 'h1[data-zta="ProductTitle__Title"]')
            ?? PageMarkup::meta($crawler, 'meta[property="og:title"]')
            ?? 'Zooplus product';
    }

    private static function ogImage(Crawler $crawler): ?string
    {
        return PageMarkup::ogImage($crawler);
    }
}
