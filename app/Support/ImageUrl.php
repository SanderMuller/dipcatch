<?php declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DomCrawler\UriResolver;

final class ImageUrl
{
    /**
     * Image URLs come from scraped markup and from user input, so a
     * `javascript:` or `data:` payload must never reach an `<img src>` or an
     * `og:image`. Returns null for anything that is not http(s).
     *
     * RFC 3986 states the scheme is case-insensitive, and `parse_url()`
     * returns it as written, so `HTTPS://…` is a valid URL the comparison
     * must accept.
     */
    public static function safe(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : $scheme;

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

        return self::safe(UriResolver::resolve($url, self::withoutCredentials($baseUrl)));
    }

    /**
     * A shop can redirect to a URL carrying credentials, and the resolver
     * copies the base's authority verbatim. Storing those in `image_url` would
     * put the shop's own credentials into a page and an email, so they are
     * dropped here — an image never needs them.
     */
    private static function withoutCredentials(string $baseUrl): string
    {
        $marker = strpos($baseUrl, '//');

        if ($marker === false) {
            return $baseUrl;
        }

        // The authority runs from `//` to the first delimiter after it. An
        // `@` anywhere past that belongs to the path, the query or the
        // fragment, and is not a credential.
        $start = $marker + 2;
        $length = strcspn($baseUrl, '/?#', $start);
        $authority = substr($baseUrl, $start, $length);
        $at = strrpos($authority, '@');

        if ($at === false) {
            return $baseUrl;
        }

        return substr($baseUrl, 0, $start) . substr($authority, $at + 1) . substr($baseUrl, $start + $length);
    }
}
