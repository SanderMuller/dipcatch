<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use JsonException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for welkoop.nl (Salesforce PWA).
 *
 * The schema.org Offer on the page is the struck-through list price
 * (verified 2026-09-11: JSON-LD 31.50 against a current price of 26.77).
 * The amount a shopper pays is on `aria-label="Huidige prijs …"`, the
 * live region the storefront itself announces. JSON-LD is the list price,
 * so a missing label is a failure, not a fallback.
 */
final readonly class WelkoopAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'welkoop';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'welkoop.nl')) {
            return ExtractionResult::skip();
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $price = self::currentPrice($crawler);
        if ($price === null) {
            return ExtractionResult::failed('welkoop_extraction_failed');
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: self::title($crawler),
            imageUrl: self::jsonLdImage($crawler),
            price: $price,
            currency: 'EUR',
            inStock: true,
            raw: ['source' => 'welkoop-huidige-prijs'],
        ));
    }

    private static function currentPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[aria-label^="Huidige prijs"]')->first();
        if ($node->count() === 0) {
            return null;
        }

        $label = $node->attr('aria-label');

        return PriceNormalizer::fromMixed(is_string($label) ? $label : null);
    }

    private static function title(Crawler $crawler): string
    {
        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = trim($h1->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        foreach (['meta[name="og:title"]', 'meta[property="og:title"]'] as $selector) {
            $og = $crawler->filter($selector)->first();
            if ($og->count() === 0) {
                continue;
            }

            $content = $og->attr('content');
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
        }

        return 'Welkoop product';
    }

    private static function jsonLdImage(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            try {
                $decoded = json_decode($node->textContent, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($decoded)) {
                continue;
            }

            $items = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($items as $item) {
                if (! is_array($item) || ($item['@type'] ?? null) !== 'Product') {
                    continue;
                }

                $image = $item['image'] ?? null;
                if (is_string($image) && $image !== '') {
                    return $image;
                }

                if (is_array($image)) {
                    $first = array_first($image);
                    if (is_string($first) && $first !== '') {
                        return $first;
                    }
                }
            }
        }

        return null;
    }
}
