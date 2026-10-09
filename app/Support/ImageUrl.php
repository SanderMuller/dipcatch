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
     * A small version of a shop's product image, for a thumbnail of 64 px at
     * most. Some image servers resize on request, and a Shopify shop can
     * otherwise send a 1.5 MB image for a 40 px tile. Each rule asks for a
     * size that server accepts: AH, Jumbo and Zooplus refuse sizes they do
     * not list. Any other URL comes back unchanged.
     */
    public static function thumbnail(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        return match (true) {
            $host === 'static.ah.nl' && is_string($query['rendition'] ?? null) => self::withQuery($url, $query, ['rendition' => (string) preg_replace('/^\d+x\d+/', '200x200', $query['rendition'])]),
            $host === 'web-fileserver.dirk.nl' => self::withQuery($url, $query, ['width' => '160', 'height' => '160']),
            $host === 'cdn.shopify.com' || str_starts_with($path, '/cdn/shop/') => self::withQuery($url, array_diff_key($query, ['height' => true, 'crop' => true]), ['width' => '160']),
            str_ends_with($host, 'media-amazon.com') && str_starts_with($path, '/images/I/') => str_replace($path, (string) preg_replace('/(\._[^\/]*_)?\.(jpe?g|png|webp)$/i', '._SL160_.$2', $path), $url),
            default => $url,
        };
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, string>  $changes
     */
    private static function withQuery(string $url, array $query, array $changes): string
    {
        $base = strtok($url, '?#');

        return $base . '?' . http_build_query([...$query, ...$changes], encoding_type: PHP_QUERY_RFC3986);
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
