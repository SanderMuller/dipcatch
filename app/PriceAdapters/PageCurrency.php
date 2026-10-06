<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The currency of a price {@see GenericAdapter} found in a page's text.
 *
 * A symbol decides, except a bare `$`: it is the sign of the Canadian,
 * Australian and other dollars too. petsmart.ca printed "$38.99" on a page
 * whose JSON-LD says CAD, and the price was stored as US dollars
 * (2026-10-06). A dollar currency the page states wins over the USD guess.
 */
final readonly class PageCurrency
{
    /** @var array<string, string> */
    private const array SYMBOLS = [
        '€' => 'EUR',
        '$' => 'USD',
        '£' => 'GBP',
        '¥' => 'JPY',
    ];

    public static function of(string $priceText, Crawler $page): ?string
    {
        foreach (self::SYMBOLS as $symbol => $iso) {
            if (str_contains($priceText, $symbol)) {
                return $symbol === '$' ? (self::statedDollar($page) ?? $iso) : $iso;
            }
        }

        if (preg_match('/\b(EUR|USD|GBP|JPY|CHF|SEK|NOK|DKK|PLN|CZK)\b/i', $priceText, $m)) {
            return strtoupper($m[1]);
        }

        return self::stated($page);
    }

    /** The currency the page's microdata states. */
    private static function stated(Crawler $page): ?string
    {
        $node = $page->filter('[itemprop="priceCurrency"]')->first();

        if ($node->count() === 0) {
            return null;
        }

        $content = $node->attr('content') ?? trim($node->text(''));

        return $content === '' ? null : strtoupper($content);
    }

    /**
     * A dollar currency the page states anywhere a price's currency is
     * stated: microdata, a `price:currency` meta tag, or JSON-LD.
     */
    private static function statedDollar(Crawler $page): ?string
    {
        $candidates = [self::stated($page)];

        foreach (['og:price:currency', 'product:price:currency'] as $property) {
            $meta = $page->filter('meta[property="' . $property . '"]')->first();
            $candidates[] = $meta->count() === 0 ? null : strtoupper(trim((string) $meta->attr('content')));
        }

        if (preg_match('/"priceCurrency"\s*:\s*"([A-Za-z]{3})"/', $page->html(''), $m) === 1) {
            $candidates[] = strtoupper($m[1]);
        }

        return array_find($candidates, static fn (?string $code): bool => $code !== null && preg_match('/^[A-Z]{2}D$/', $code) === 1);
    }
}
