<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\Support\UrlNormalizer;

/**
 * The two URL questions every host adapter asks: is this my host, and which
 * product does the URL name?
 *
 * Both were copied into each adapter, which is how one of them ends up with
 * a subtly different rule — a suffix check without the dot boundary matches
 * `notdirk.nl`, and an id read from the wrong segment prices the wrong
 * article.
 */
final class HostUrl
{
    /**
     * True when the URL's host is `$host` or one of its subdomains. The
     * comparison runs on the normalized host, so `WWW.Dirk.NL.` matches
     * `dirk.nl`.
     */
    public static function matches(string $url, string $host): bool
    {
        $actual = parse_url($url, PHP_URL_HOST);

        if (! is_string($actual) || $actual === '') {
            return false;
        }

        $actual = UrlNormalizer::normalizeHost($actual);

        return $actual === $host || str_ends_with($actual, '.' . $host);
    }

    /**
     * The last path segment when it is a plain number — the product id in
     * `/boodschappen/x/x/x/115212`. Null when the URL names no article, which
     * an adapter must treat as "cannot price this page" rather than guessing.
     */
    public static function lastNumericSegment(string $url): ?string
    {
        $last = self::lastSegment($url);

        return $last !== null && ctype_digit($last) ? $last : null;
    }

    /**
     * The digits of a prefixed last segment, e.g. `p10033095` → `10033095`.
     */
    public static function lastSegmentDigits(string $url, string $prefix): ?string
    {
        $last = self::lastSegment($url);

        if ($last === null || ! preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $last, $m)) {
            return null;
        }

        return $m[1];
    }

    private static function lastSegment(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $last = end($segments);

        return is_string($last) && $last !== '' ? $last : null;
    }
}
