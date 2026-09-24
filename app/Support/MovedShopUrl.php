<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use InvalidArgumentException;

/**
 * The address a shop moved a tracked page to for good, when it is safe to
 * store in place of the old one.
 *
 * A stored URL that redirects costs a second request on every check, a
 * millisecond after the first — the burst dierapotheker.nl answered with 429.
 * New shops are saved in the form the shop canonicalises to, but a shop saved
 * before that fix, or one whose shop moved its page since, paid for the
 * redirect on every check until someone added it again.
 *
 * Only what the shop itself says is permanent, and only within the same
 * shop: a redirect to another host is a shop closing or merging, which a
 * person should see rather than have followed quietly.
 */
final readonly class MovedShopUrl
{
    /**
     * The columns to write for a check that fetched `$movedFrom` and landed
     * on `$movedTo` through permanent redirects, or none. None when the stored
     * URL is no longer the one fetched: someone repointed the shop during the
     * fetch, and the old page's move must not overwrite their choice.
     *
     * @return array{url?: string, url_hash?: string}
     */
    public static function updates(Shop $shop, ?string $movedFrom, ?string $movedTo): array
    {
        $moved = $movedTo === null || $movedFrom !== $shop->url ? null : self::for($shop, $movedTo);

        return $moved === null ? [] : ['url' => $moved, 'url_hash' => UrlNormalizer::hash($moved)];
    }

    /** The normalized URL to store, or null to keep the one stored. */
    private static function for(Shop $shop, string $finalUrl): ?string
    {
        try {
            $moved = UrlNormalizer::normalize($finalUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($moved === $shop->url || ! self::sameHost($moved, $shop->url) || ! self::keepsQuery($moved, $shop->url)) {
            return null;
        }

        // Another row of this product already tracks that page.
        $taken = Shop::query()
            ->where('product_id', $shop->product_id)
            ->where('url_hash', UrlNormalizer::hash($moved))
            ->whereKeyNot($shop->getKey())
            ->exists();

        return $taken ? null : $moved;
    }

    private static function sameHost(string $a, string $b): bool
    {
        return UrlNormalizer::normalizeHost((string) parse_url($a, PHP_URL_HOST))
            === UrlNormalizer::normalizeHost((string) parse_url($b, PHP_URL_HOST));
    }

    /**
     * Every parameter of the stored URL, with its value. A redirect that drops
     * `?variant=` lands on the page default, and storing that would lose which
     * variant the shop was tracking.
     */
    private static function keepsQuery(string $moved, string $stored): bool
    {
        // Pairs as written, so a repeated key is not collapsed into one.
        $now = self::pairs($moved);

        foreach (self::pairs($stored) as $pair) {
            $at = array_search($pair, $now, true);

            if ($at === false) {
                return false;
            }

            unset($now[$at]);
        }

        return true;
    }

    /** @return list<string> */
    private static function pairs(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query === '' ? [] : explode('&', $query);
    }
}
