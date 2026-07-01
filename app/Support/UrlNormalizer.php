<?php declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class UrlNormalizer
{
    /** @var list<string> */
    private const array TRACKING_PARAM_EXACT = [
        'gclid', 'fbclid', 'mc_eid', 'mc_cid', 'ref', 'ref_src', '_ga',
    ];

    /** @var list<string> */
    private const array TRACKING_PARAM_PREFIXES = ['utm_'];

    /**
     * Normalize a URL so two URLs that point at the same resource produce the
     * same string: lowercase scheme + host, strip default ports, strip the
     * trailing slash on non-root paths, drop `utm_*` query params, sort
     * remaining params alphabetically.
     *
     * @throws InvalidArgumentException when the input is not a parseable http/https URL.
     */
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Invalid URL: '{$url}'.");
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Unsupported URL scheme '{$scheme}' in '{$url}'.");
        }

        $host = self::normalizeHost($parts['host']);

        $port = $parts['port'] ?? null;
        $portSegment = self::normalizePort($scheme, $port);

        $path = self::normalizePath($parts['path'] ?? '');
        $query = self::normalizeQuery($parts['query'] ?? '');

        return $scheme . '://' . $host . $portSegment . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Public so callers (e.g. Shop model) can compute the canonical host
     * separately from the full URL — for `offers.host` and rate-limit keys.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '') {
            throw new InvalidArgumentException('Empty host after normalization.');
        }

        if (function_exists('idn_to_ascii')) {
            $idn = @idn_to_ascii(
                $host,
                IDNA_NONTRANSITIONAL_TO_ASCII,
                INTL_IDNA_VARIANT_UTS46,
            );

            if (is_string($idn) && $idn !== '') {
                $host = strtolower($idn);
            }
        }

        return $host;
    }

    public static function hash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    private static function normalizePort(string $scheme, ?int $port): string
    {
        if ($port === null) {
            return '';
        }

        if ($scheme === 'http' && $port === 80) {
            return '';
        }

        if ($scheme === 'https' && $port === 443) {
            return '';
        }

        return ':' . $port;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        // Canonicalize percent-encoding by decoding then re-encoding, but
        // preserve the path segment structure.
        $segments = explode('/', $path);
        $segments = array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            $segments,
        );
        $path = implode('/', $segments);

        // Strip trailing slash on non-root paths.
        if (str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private static function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $piece) {
            if ($piece === '') {
                continue;
            }

            $eq = strpos($piece, '=');
            if ($eq === false) {
                $key = rawurldecode($piece);
                $value = '';
            } else {
                $key = rawurldecode(substr($piece, 0, $eq));
                $value = rawurldecode(substr($piece, $eq + 1));
            }

            if (self::isTrackingParam($key)) {
                continue;
            }

            $pairs[] = [$key, $value];
        }

        usort($pairs, static function (array $a, array $b): int {
            $keyCmp = strcmp($a[0], $b[0]);

            if ($keyCmp !== 0) {
                return $keyCmp;
            }

            return strcmp($a[1], $b[1]);
        });

        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $pairs,
        ));
    }

    private static function isTrackingParam(string $key): bool
    {
        if (in_array($key, self::TRACKING_PARAM_EXACT, strict: true)) {
            return true;
        }

        return array_any(self::TRACKING_PARAM_PREFIXES, fn (string $prefix): bool => str_starts_with($key, $prefix));
    }
}
