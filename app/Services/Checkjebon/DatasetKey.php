<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\Support\UrlNormalizer;

/**
 * How a checkjebon dataset row is keyed, from both sides: the import reads
 * the dataset's link, a lookup reads the shop's own product URL. They must
 * land on the same `external_id`, so both rules live here. When they drift
 * nothing throws: every lookup becomes an ordinary "not in dataset" miss.
 */
final class DatasetKey
{
    /** @var array<string, string> Normalized host → dataset supermarket key, for the chains a URL can be looked up for. */
    private const array PRICED_HOSTS = [
        'ah.nl' => 'ah',
        'boodschaapje.nl' => 'lidl',
    ];

    /**
     * The dataset supermarket a host's product pages are looked up in, or
     * null when the dataset cannot price that host.
     */
    public static function supermarketForHost(string $host): ?string
    {
        if (isset(self::PRICED_HOSTS[$host])) {
            return self::PRICED_HOSTS[$host];
        }

        foreach (self::PRICED_HOSTS as $candidate => $supermarket) {
            if (str_ends_with($host, '.' . $candidate)) {
                return $supermarket;
            }
        }

        return null;
    }

    /**
     * The key the import stores for a dataset link. AH links look like
     * `wi257/ah-kruiden-roomkaas`; Lidl links are the bare numeric
     * boodschaapje product id. Every other chain is match-only: the link
     * itself is the id, whether a slug or a number.
     */
    public static function fromLink(string $supermarket, string $link): ?string
    {
        if ($supermarket === 'ah') {
            return preg_match('/^(wi\d+)\//i', $link, $m) === 1 ? strtolower($m[1]) : null;
        }

        if ($supermarket === 'lidl') {
            return ctype_digit($link) ? $link : null;
        }

        return mb_substr($link, 0, 255);
    }

    /**
     * The key a lookup reads from a shop's product URL. AH URLs carry a
     * `wi<digits>` path segment; boodschaapje product URLs end in the bare
     * numeric product id.
     */
    public static function fromUrl(string $supermarket, string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if ($supermarket === 'ah') {
            foreach ($segments as $segment) {
                if (preg_match('/^wi\d+$/i', $segment) === 1) {
                    return strtolower($segment);
                }
            }

            return null;
        }

        $last = end($segments);

        return is_string($last) && ctype_digit($last) ? $last : null;
    }

    /** The URL's normalized host, or null when it has none. */
    public static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }
}
