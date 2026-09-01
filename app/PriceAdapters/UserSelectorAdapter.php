<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\CssSelector\Exception\SyntaxErrorException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * User-supplied CSS selector adapter. Skips unless an {@see AdapterContext}
 * with a non-empty `price` selector is provided. On match, runs the selector
 * against the page and uses the context's fallback currency when the page
 * markup doesn't carry one. Used by both the Add-Shop probe (when the
 * generic chain returns `no_adapter_matched` and the user pastes a selector)
 * and the periodic re-check job (selectors persisted on the offer row).
 */
final readonly class UserSelectorAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'user-selector';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if ($context === null || ! $context->hasPriceSelector()) {
            return ExtractionResult::skip();
        }

        $priceSelector = (string) ($context->selectors['price'] ?? '');
        $titleSelector = self::nonEmpty($context->selectors['title'] ?? null);
        $imageSelector = self::nonEmpty($context->selectors['image'] ?? null);

        $crawler = self::crawler($html);

        try {
            $priceNode = $crawler->filter($priceSelector)->first();
        } catch (SyntaxErrorException) {
            return ExtractionResult::failed('user_selector_invalid');
        }

        if ($priceNode->count() === 0) {
            return ExtractionResult::failed('user_selector_no_match');
        }

        $rawText = trim($priceNode->text(''));
        $candidate = self::nonEmpty($priceNode->attr('data-price'))
            ?? self::nonEmpty($priceNode->attr('content'))
            ?? $rawText;

        $price = PriceNormalizer::fromMixed($candidate);
        if ($price === null) {
            return ExtractionResult::failed('user_selector_no_price');
        }

        $currency = $context->fallbackCurrency;
        if ($currency === null || $currency === '') {
            return ExtractionResult::failed('user_selector_no_currency');
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: self::extractFromSelector($crawler, $titleSelector) ?? self::fallbackTitle($crawler),
            imageUrl: self::extractImage($crawler, $imageSelector),
            price: $price,
            currency: strtoupper($currency),
            inStock: true,
            raw: ['source' => 'user-selector', 'price_selector' => $priceSelector],
        ));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function extractFromSelector(Crawler $crawler, ?string $selector): ?string
    {
        if ($selector === null) {
            return null;
        }

        try {
            $node = $crawler->filter($selector)->first();
        } catch (SyntaxErrorException) {
            return null;
        }

        if ($node->count() === 0) {
            return null;
        }

        return self::nonEmpty($node->text(''));
    }

    private static function extractImage(Crawler $crawler, ?string $selector): ?string
    {
        if ($selector !== null) {
            try {
                $node = $crawler->filter($selector)->first();
            } catch (SyntaxErrorException) {
                $node = null;
            }
            if ($node !== null && $node->count() > 0) {
                $src = self::nonEmpty($node->attr('src'))
                    ?? self::nonEmpty($node->attr('content'))
                    ?? self::nonEmpty($node->attr('href'));
                if ($src !== null) {
                    return $src;
                }
            }
        }

        $og = $crawler->filter('meta[property="og:image"]')->first();
        if ($og->count() > 0) {
            $content = $og->attr('content');
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return null;
    }

    private static function fallbackTitle(Crawler $crawler): string
    {
        $og = $crawler->filter('meta[property="og:title"]')->first();
        if ($og->count() > 0) {
            $content = self::nonEmpty($og->attr('content'));
            if ($content !== null) {
                return $content;
            }
        }

        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = self::nonEmpty($h1->text(''));
            if ($text !== null) {
                return $text;
            }
        }

        $title = $crawler->filter('title')->first();
        if ($title->count() > 0) {
            $text = self::nonEmpty($title->text(''));
            if ($text !== null) {
                return $text;
            }
        }

        return 'Unknown';
    }
}
