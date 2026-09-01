<?php declare(strict_types=1);

namespace App\Support;

/**
 * Static facts about the checkjebon dataset chains that the dataset itself
 * does not carry: which hosts a chain can be tracked under, and whether the
 * app can price one of its URLs today.
 */
final class SupermarketChains
{
    /**
     * Chains the price engine can resolve. Every other chain is suggested
     * for comparison only — see `specs/shop-suggestions.md` Section 3.
     *
     * `hoogvliet` is deliberately absent: its pages parse through the
     * generic adapter but return a wrong number (22.33 for a 3.09 product),
     * and a confidently wrong price is worse than none.
     *
     * @var list<string>
     */
    private const array TRACKABLE = ['ah', 'dirk', 'jumbo', 'lidl', 'spar'];

    /**
     * Hosts a chain can be tracked under beyond its dataset base URL. Lidl
     * links to boodschaapje.nl in the dataset, but `LidlAdapter` scrapes
     * lidl.nl directly — a product already tracking lidl.nl must not be
     * offered the boodschaapje row.
     *
     * @var array<string, list<string>>
     */
    private const array EXTRA_HOSTS = [
        'lidl' => ['lidl.nl'],
    ];

    public static function isTrackable(string $chain): bool
    {
        return in_array($chain, self::TRACKABLE, true);
    }

    /**
     * Every host the chain can already be tracked under, normalized the same
     * way `Shop::booted()` normalizes `shops.host`.
     *
     * @return list<string>
     */
    public static function hosts(string $chain, string $baseUrl): array
    {
        $host = UrlNormalizer::normalizeHost((string) parse_url($baseUrl, PHP_URL_HOST));

        $hosts = $host === '' ? [] : [$host];

        foreach (self::EXTRA_HOSTS[$chain] ?? [] as $extra) {
            $hosts[] = UrlNormalizer::normalizeHost($extra);
        }

        return array_values(array_unique($hosts));
    }
}
