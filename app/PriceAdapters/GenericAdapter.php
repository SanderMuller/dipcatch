<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Last-resort heuristic adapter. Looks for `.price`, `[data-price]`,
 * `[class*="price"]` text nodes containing a currency-prefixed number.
 *
 * Low confidence: this is the fallback when JSON-LD / microdata / OG all
 * skip. Skips when no plausible match found (so the chain can return
 * `no_adapter_matched` instead of polluting success metrics).
 */
final readonly class GenericAdapter implements ShopAdapter
{
    /** @var list<string> */
    private const array PRICE_SELECTORS = [
        '[itemprop="price"]',
        '[data-price]',
        '.product-price',
        '.price',
        '[class*="price"]',
    ];

    /** @var array<string, string> */
    private const array CURRENCY_SYMBOLS = [
        '€' => 'EUR',
        '$' => 'USD',
        '£' => 'GBP',
        '¥' => 'JPY',
    ];

    public function key(): string
    {
        return 'generic';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $crawler = self::crawler($html);

        foreach (self::PRICE_SELECTORS as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }

            $rawText = trim($node->text(''));
            $dataPrice = $node->attr('data-price') ?? $node->attr('content');

            $candidate = is_string($dataPrice) && $dataPrice !== '' ? $dataPrice : $rawText;

            if ($candidate === '') {
                continue;
            }

            $price = PriceNormalizer::fromMixed($candidate);
            if ($price === null) {
                continue;
            }

            $currency = self::detectCurrency($rawText) ?? self::detectCurrencyMeta($crawler);
            if ($currency === null) {
                continue;
            }

            $title = self::detectTitle($crawler);
            $image = self::detectImage($crawler);

            return ExtractionResult::success(new ShopSnapshot(
                title: $title,
                imageUrl: $image,
                price: $price,
                currency: $currency,
                inStock: true,
                raw: ['source' => 'generic', 'matched_selector' => $selector],
            ));
        }

        return ExtractionResult::skip();
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    private static function detectCurrency(string $text): ?string
    {
        foreach (self::CURRENCY_SYMBOLS as $symbol => $iso) {
            if (str_contains($text, $symbol)) {
                return $iso;
            }
        }

        // Three-letter ISO code embedded?
        if (preg_match('/\b(EUR|USD|GBP|JPY|CHF|SEK|NOK|DKK|PLN|CZK)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private static function detectCurrencyMeta(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[itemprop="priceCurrency"]')->first();
        if ($node->count() > 0) {
            $content = $node->attr('content') ?? trim($node->text(''));
            if ($content !== '') {
                return strtoupper($content);
            }
        }

        return null;
    }

    private static function detectTitle(Crawler $crawler): string
    {
        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = trim($h1->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        $titleTag = $crawler->filter('title')->first();
        if ($titleTag->count() > 0) {
            $text = trim($titleTag->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        return 'Unknown';
    }

    private static function detectImage(Crawler $crawler): ?string
    {
        $og = $crawler->filter('meta[property="og:image"]')->first();
        if ($og->count() > 0) {
            $content = $og->attr('content');
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return null;
    }
}
