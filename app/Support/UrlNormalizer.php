<?php declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class UrlNormalizer
{
    /**
     * Click and campaign ids that never change what a page sells. Adding one
     * here changes the hash of every stored URL that carries it, so a row
     * saved before the change no longer matches its own page pasted again —
     * run `dipcatch:renormalize-shop-urls` after extending this list.
     *
     * Never a parameter that picks what is sold: `variant`, `sku`,
     * `activeVariant`, `id`, `color`, `size` — and Amazon's `th` and `psc`,
     * which look like tracking and select a variant.
     *
     * @var list<string>
     */
    private const array TRACKING_PARAM_EXACT = [
        'gclid', 'fbclid', 'mc_eid', 'mc_cid', 'ref', 'ref_src', '_ga',
        // Google: Shopping's link id, and the ads ids that replaced gclid.
        'srsltid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', '_gl',
        // Other ad networks and social apps.
        'msclkid', 'dclid', 'yclid', 'igshid', 'ttclid', 'twclid', 'li_fat_id',
        // Mail and marketing automation.
        '_hsenc', '_hsmi', 'mkt_tok',
        // Affiliate networks: Awin, Commission Junction, Impact.
        'awc', 'cjevent', 'irclickid',
    ];

    /** @var list<string> */
    private const array TRACKING_PARAM_PREFIXES = ['utm_'];

    /**
     * Normalize a URL so two URLs that point at the same resource produce the
     * same string: lowercase scheme + host, strip default ports, keep the path
     * as the shop wrote it, drop tracking query params, sort remaining params
     * alphabetically.
     *
     * The trailing slash is kept, because it is the shop's own canonical form
     * and stripping it bought nothing. SPAR answers 404 without it. Shopware
     * answers 301 — and following that redirect is a second request a
     * millisecond after the first, which is a burst. dierapotheker.nl rate
     * limits on exactly that shape, so every probe there was refused while a
     * direct request to the same page always succeeded: nine failures against
     * five successes, measured on production 2026-09-22.
     *
     * Identity is unaffected. {@see hash()} strips the trailing slash before
     * hashing, so `/p/1` and `/p/1/` remain one shop and every hash stored
     * before this still matches.
     *
     * The `www.` prefix is deliberately KEPT here: not every shop serves its
     * apex domain (vomar.nl answers 404 while www.vomar.nl serves the page),
     * and this string is what the fetcher requests. Dedupe is unaffected —
     * {@see hash()} drops the prefix before hashing, so the two forms still
     * collapse to one shop.
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

        $host = self::canonicalHost($parts['host']);

        $port = $parts['port'] ?? null;
        $portSegment = self::normalizePort($scheme, $port);

        $path = self::normalizePath($parts['path'] ?? '');
        $query = self::normalizeQuery($parts['query'] ?? '');

        return $scheme . '://' . $host . $portSegment . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Host as the site serves it: lowercased, IDN-encoded, without the DNS
     * root dot — `www.` intact, because not every shop answers on its apex.
     */
    public static function canonicalHost(string $host): string
    {
        $host = strtolower($host);
        // A fully qualified name ends in the DNS root dot ("plus.nl."), which
        // resolves to the same site — keeping it would let such a URL slip
        // past every host comparison in the app.
        $host = rtrim($host, '.');

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

    /**
     * The comparison form of a host, `www.` dropped: `shops.host`, rate-limit
     * keys and every host allow-list compare on this.
     */
    public static function normalizeHost(string $host): string
    {
        return preg_replace('/^www\./', '', self::canonicalHost($host)) ?? self::canonicalHost($host);
    }

    /**
     * Dedupe key for a normalized URL. `www.` is dropped here rather than in
     * {@see normalize()}, so `www.shop.test/p` and `shop.test/p` are one
     * shop while each keeps the host that actually serves it.
     *
     * The trailing slash goes the same way, and for the same reason: a URL is
     * fetched as the shop writes it, and compared as the resource it names.
     * This is also what keeps the slash free to be preserved — every hash
     * written while it was being stripped still matches.
     */
    public static function hash(string $normalizedUrl): string
    {
        $comparisonUrl = preg_replace('#^(https?://)www\.#', '$1', $normalizedUrl) ?? $normalizedUrl;

        if (parse_url($comparisonUrl, PHP_URL_PATH) !== '/') {
            $parts = explode('?', $comparisonUrl, 2);
            $withoutSlash = rtrim($parts[0], '/');
            $comparisonUrl = ($withoutSlash === '' ? $parts[0] : $withoutSlash)
                . (isset($parts[1]) ? '?' . $parts[1] : '');
        }

        return hash('sha256', $comparisonUrl);
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
        // Links are written by hand as often as by tools: `UTM_Source` is the
        // same campaign tag.
        $key = strtolower($key);

        if (in_array($key, self::TRACKING_PARAM_EXACT, strict: true)) {
            return true;
        }

        return array_any(self::TRACKING_PARAM_PREFIXES, fn (string $prefix): bool => str_starts_with($key, $prefix));
    }
}
