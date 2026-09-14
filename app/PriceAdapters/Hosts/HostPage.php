<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\Support\PackSize;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What a product page states in its markup, for the adapters that read it
 * with CSS rather than structured data.
 *
 * A source present but blank states nothing, so the caller chains its sources
 * with `??` and the first that speaks wins. The order is the part that differs
 * per host and the part that matters: {@see PackSize::resolve()} parses the
 * title for a pack size whenever the source states none, so the order a host
 * reads its title in decides the unit price on screen.
 */
final readonly class HostPage
{
    /** What a `meta` tag states in its `content` attribute. */
    public static function meta(Crawler $crawler, string $selector): ?string
    {
        return self::read($crawler, $selector, attribute: 'content');
    }

    /** What an element states as its text. */
    public static function element(Crawler $crawler, string $selector): ?string
    {
        return self::read($crawler, $selector, attribute: null);
    }

    /** The image the page states for sharing, which is the product shot. */
    public static function ogImage(Crawler $crawler): ?string
    {
        return self::meta($crawler, 'meta[property="og:image"]');
    }

    private static function read(Crawler $crawler, string $selector, ?string $attribute): ?string
    {
        $node = $crawler->filter($selector)->first();

        if ($node->count() === 0) {
            return null;
        }

        $value = $attribute === null ? $node->text('') : $node->attr($attribute);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
