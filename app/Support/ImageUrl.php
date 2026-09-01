<?php declare(strict_types=1);

namespace App\Support;

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
     */
    public static function absolute(mixed $url, string $baseUrl): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (is_string(parse_url($url, PHP_URL_SCHEME))) {
            return self::safe($url);
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? null;
        $host = $base['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $authority = $host . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return self::safe($scheme . ':' . $url);
        }

        if (str_starts_with($url, '/')) {
            return self::safe($scheme . '://' . $authority . $url);
        }

        $path = is_string($base['path'] ?? null) ? $base['path'] : '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return self::safe($scheme . '://' . $authority . $directory . $url);
    }
}
