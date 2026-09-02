<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Support\UrlNormalizer;

/**
 * Decides whether a structured-data entity's `url` names the page that was
 * requested.
 *
 * A variant page lists all its variants with the same path, differing only
 * in the query (`?activeVariant=169589.19`), so a path-only comparison makes
 * every variant match and the first one listed wins — zooplus.nl served
 * 27.99 for a page selling 59.99 (verified 2026-09-02).
 */
final readonly class EntityUrl
{
    /**
     * Query parameters that never identify a product: campaign and click
     * tracking a shop appends to its own canonical URLs. Counting one of
     * these as identifying would let a canonical entity carrying
     * `?utm_source=mail` outrank the entity that names the variant.
     *
     * @var list<string>
     */
    private const array TRACKING = [
        'gclid', 'fbclid', 'msclkid', 'srsltid', 'mc_cid', 'mc_eid', 'ref', 'referrer',
    ];

    /**
     * The paths must be equal, and every query parameter the entity states
     * must be present with the same value in the requested URL. Extra
     * parameters on the requested URL are ignored: they are the caller's
     * tracking noise, not part of the entity's identity.
     */
    public static function matches(string $entityUrl, string $url): bool
    {
        if (! self::sameDocument($entityUrl, $url)) {
            return false;
        }

        $requested = self::query($url);

        foreach (self::query($entityUrl) as $key => $value) {
            if (($requested[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Same host and same path. Schema.org allows a relative entity `url`,
     * which names this page by definition, so it is compared on path alone.
     * The scheme is ignored: an entity that still states `http` describes
     * the same page as the `https` one that was fetched.
     */
    private static function sameDocument(string $entityUrl, string $url): bool
    {
        if (self::path($entityUrl) !== self::path($url)) {
            return false;
        }

        $entityHost = self::host($entityUrl);

        return $entityHost === null || $entityHost === self::host($url);
    }

    /**
     * How precisely the entity's URL pins the request down: the number of
     * query parameters it states. Zero means it names the page rather than
     * a variant of it, and -1 means it is not this page at all.
     *
     * A count rather than a yes/no, because not every parameter identifies
     * a variant: an entity URL carrying `?utm_source=mail` states one
     * parameter, and must lose to the entry that also states the variant.
     */
    public static function precision(string $entityUrl, string $url): int
    {
        return self::matches($entityUrl, $url) ? count(self::query($entityUrl)) : -1;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }

    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return rtrim(strtolower(is_string($path) ? $path : ''), '/');
    }

    /**
     * @return array<int|string, array<mixed>|string>
     */
    private static function query(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parsed);

        foreach (array_keys($parsed) as $key) {
            if (self::isTracking((string) $key)) {
                unset($parsed[$key]);
            }
        }

        return $parsed;
    }

    private static function isTracking(string $key): bool
    {
        $key = strtolower($key);

        return str_starts_with($key, 'utm_') || str_starts_with($key, '_ga')
            || in_array($key, self::TRACKING, strict: true);
    }
}
