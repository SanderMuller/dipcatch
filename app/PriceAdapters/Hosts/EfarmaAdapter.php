<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Gtin;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for efarma.nl.
 *
 * The page carries no structured product data, and it splits the price over
 * two elements: `<span class="euro_price"> 20,</span><small
 * class="cent_price">89</small>`. A selector on one element reads "20," and
 * loses the cents. A quantity-discount table can come first with the same
 * classes, so the price is the one that follows the "Prijs:" label
 * (Roter Vitamine C, 2026-10-04).
 */
final readonly class EfarmaAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'efarma';
    }

    public function ownedHosts(): array
    {
        return ['efarma.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $productId = HostUrl::lastNumericSegment($url);
        $itemId = $crawler->filter('select[data-itemid]')->first();

        // A redirect or a stale response would otherwise price another article.
        if ($productId === null || $itemId->count() === 0 || $itemId->attr('data-itemid') !== $productId) {
            return ExtractionResult::failed('efarma_product_mismatch');
        }

        $price = self::price($crawler);

        if ($price === null) {
            return ExtractionResult::failed('efarma_no_price');
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: PageMarkup::element($crawler, 'h1') ?? 'Unknown',
            imageUrl: self::image($crawler),
            price: $price,
            // A Dutch-only shop.
            currency: 'EUR',
            inStock: self::inStock($crawler),
            raw: ['source' => 'efarma'],
            gtin: self::gtin($crawler),
            gtinAuthoritative: true,
        ));
    }

    /** "20," + "89" → "20.89"; null when the label or either part is missing. */
    private static function price(Crawler $crawler): ?string
    {
        $label = $crawler->filter('p.col_text')
            ->reduce(static fn (Crawler $node): bool => str_starts_with(trim(str_replace("\u{a0}", ' ', $node->text(''))), 'Prijs:'))
            ->first();

        if ($label->count() === 0) {
            return null;
        }

        $euros = $label->nextAll()->filter('span.euro_price')->first();
        $cents = $euros->count() > 0 ? $euros->nextAll()->filter('small.cent_price')->first() : $euros;

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

    /** The product photo; the panel also holds badges such as a dermacosmetics stamp. */
    private static function image(Crawler $crawler): ?string
    {
        $image = $crawler->filter('.zoom_img_panel img[src*="/itempics/"]')->first();

        return $image->count() > 0 ? $image->attr('src') : null;
    }

    /** "direct leverbaar" or a delivery time is in stock, "niet op voorraad" is not; null when neither shows. */
    private static function inStock(Crawler $crawler): ?bool
    {
        if ($crawler->filter('h6.in_stock')->count() > 0) {
            return true;
        }

        $outOfStock = $crawler->filter('label.col_warning')
            ->reduce(static fn (Crawler $node): bool => str_contains(mb_strtolower($node->text('')), 'niet op voorraad'));

        return $outOfStock->count() > 0 ? false : null;
    }

    /** "EAN: 8713304941826" in the product details. */
    private static function gtin(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('p') as $node) {
            if (preg_match('/EAN:\s*(\d{8,14})/', $node->textContent, $m) === 1) {
                return Gtin::normalize($m[1]);
            }
        }

        return null;
    }
}
