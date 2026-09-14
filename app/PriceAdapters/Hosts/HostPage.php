<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\Support\PackSize;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What a product page states in its markup, for the adapters that read it
 * with CSS rather than structured data.
 *
 * The recipe — try each source in turn, take the first that says something —
 * was written out once per adapter. The order is the part that differs per
 * host and the part that matters: {@see PackSize::resolve()}
 * parses the title for a pack size whenever the source states none, so the
 * order a host reads its title in decides the unit price on screen.
 */
final readonly class HostPage
{
    /**
     * The first of these selectors that states something, trimmed.
     *
     * A `meta[...]` selector is read from its `content` attribute, anything
     * else from the element's text. A source present but blank is a source
     * that says nothing, so the next one gets its turn.
     */
    public static function text(Crawler $crawler, string ...$selectors): ?string
    {
        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();

            if ($node->count() === 0) {
                continue;
            }

            $value = str_starts_with($selector, 'meta[')
                ? $node->attr('content')
                : $node->text('');

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** The image the page states for sharing, which is the product shot. */
    public static function ogImage(Crawler $crawler): ?string
    {
        return self::text($crawler, 'meta[property="og:image"]');
    }
}
