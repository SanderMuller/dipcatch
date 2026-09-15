<?php declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DomCrawler\UriResolver;

final class ImageUrl
{
    /**
     * Image URLs come from scraped markup and from user input, so a
     * `javascript:` or `data:` payload must never reach an `<img src>` or an
     * `og:image`. Returns null for anything that is not http(s).
     */
    public static function safe(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https' ? $url : null;
    }

    /**
     * Adapters read image attributes straight from the markup, so a shop can
     * hand back `/img/p.jpg` or `//cdn/p.jpg`. Resolve those against the page
     * they came from — safe() drops an unresolved relative URL, and the image
     * is lost.
     *
     * The blank guard is load-bearing, not defensive: RFC 3986 resolves an
     * empty reference to the base URI itself, so without it a page stating
     * `<meta property="og:image" content="">` would store its own address as
     * the product photo.
     */
    public static function absolute(mixed $url, string $baseUrl): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return self::safe(UriResolver::resolve($url, $baseUrl));
    }
}
