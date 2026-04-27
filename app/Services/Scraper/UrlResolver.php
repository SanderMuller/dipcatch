<?php declare(strict_types=1);

namespace App\Services\Scraper;

final class UrlResolver
{
    /**
     * Resolve a possibly-relative URL against a base URL.
     *
     * Returns the original `$url` if it's already absolute or if resolution fails.
     */
    public static function resolve(string $base, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        // Protocol-relative.
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 || str_starts_with($url, 'data:')) {
            return $url;
        }

        $parts = parse_url($base);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $origin . $directory . $url;
    }
}
