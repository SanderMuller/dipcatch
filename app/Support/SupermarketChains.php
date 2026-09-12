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
    private const array TRACKABLE = ['ah', 'dirk', 'jumbo', 'lidl', 'poiesz', 'spar', 'vomar'];

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

    /**
     * Chains whose dataset links cannot be turned into a working product
     * URL, so they are never suggested. DekaMarkt is the only one today:
     * its `u` points at `/boodschappen/…` where every id answers "Het
     * artikel is niet gevonden", and the real `/producten/x/x/x/<id>`
     * pages use a different id space — five random dataset ids all miss,
     * while an id taken from dekamarkt.nl itself resolves (2026-09-01).
     * A row nobody can open or track is noise. `DekaMarktAdapter` prices
     * those pages fine, so a URL the user pastes themselves is tracked;
     * only the dataset-built link is unusable.
     *
     * @var list<string>
     */
    private const array UNLINKABLE = ['dekamarkt'];

    public static function isLinkable(string $chain): bool
    {
        return ! in_array($chain, self::UNLINKABLE, true);
    }

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
