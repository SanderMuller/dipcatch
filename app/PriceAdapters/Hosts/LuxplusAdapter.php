<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdEntities;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\StockAvailability;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for luxplus.nl.
 *
 * Luxplus sells on a paid membership, and its JSON-LD offer states the
 * member's price with no member tier to tell it apart: 3.95 on a Sanex
 * shower gel whose "Normale prijs" is 5.39 (verified 2026-10-17, on every
 * product page sampled). The price tracked is the normal one from the
 * price chooser.
 *
 * Neither the JSON-LD name nor any size field states the pack size. The
 * product-type block right after the h1 does: "Douchegel" then "600 ml". The
 * recommendation tiles reuse that class, so only that block counts.
 */
final readonly class LuxplusAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'luxplus';
    }

    public function ownedHosts(): array
    {
        return ['luxplus.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $canonical = PageMarkup::meta($crawler, 'meta[property="og:url"]');

        // A redirect or a stale response would otherwise price another product.
        if ($canonical === null || HostUrl::lastSegment($canonical) !== HostUrl::lastSegment($url)) {
            return ExtractionResult::failed('luxplus_product_mismatch');
        }

        $price = self::normalPrice($crawler);

        if ($price === null) {
            return ExtractionResult::failed('luxplus_no_price');
        }

        [$inStock, $stockSignal] = StockAvailability::read(self::offerAvailability($crawler));

        return ExtractionResult::success(new ShopSnapshot(
            title: PageMarkup::meta($crawler, 'meta[property="og:title"]') ?? PageMarkup::element($crawler, 'h1') ?? 'Unknown',
            imageUrl: PageMarkup::ogImage($crawler),
            price: $price,
            // A Dutch-only shop; every page sampled states EUR.
            currency: 'EUR',
            inStock: $inStock,
            raw: ['source' => 'luxplus'],
            packSize: PageMarkup::element($crawler, 'h1.lux-product-title + .lux-product-type p:last-child'),
            stockSignal: $stockSignal,
        ));
    }

    /** `<span>5</span><sup class="decimal-amount">39</sup>` → "5.39"; null when either part is missing. */
    private static function normalPrice(Crawler $crawler): ?string
    {
        $amount = $crawler->filter('.price-wrapper.normal .price-amount')->first();
        $euros = $amount->filter('span:not(.currency)')->first();
        $cents = $amount->filter('sup.decimal-amount')->first();

        if ($euros->count() === 0 || $cents->count() === 0) {
            return null;
        }

        $euroDigits = (string) preg_replace('/\D/', '', $euros->text(''));
        $centDigits = (string) preg_replace('/\D/', '', $cents->text(''));

        if ($euroDigits === '' || strlen($centDigits) !== 2) {
            return null;
        }

        return PriceNormalizer::fromMixed("{$euroDigits}.{$centDigits}");
    }

    /** The `availability` of the page's Product offer: its price is the member's, but its stock holds for everyone. */
    private static function offerAvailability(Crawler $crawler): mixed
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent, true);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'Product') {
                return JsonLdEntities::pickOfferFromProduct($decoded['offers'] ?? null)['availability'] ?? null;
            }
        }

        return null;
    }
}
