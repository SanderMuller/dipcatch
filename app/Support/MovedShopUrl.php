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
 * A shop is saved where its page moved to when it is added, and a shop whose
 * page moves later is repointed on its next successful check.
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

    /**
     * Where a fetch of `$fetched` that moved permanently to `$finalUrl` should
     * be stored instead, or null to keep `$fetched`. Checks the move only;
     * whether another shop already tracks the address is the caller's to ask.
     */
    public static function target(string $fetched, string $finalUrl): ?string
    {
        try {
            $moved = UrlNormalizer::normalize($finalUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($moved === $fetched || ! self::sameHost($moved, $fetched) || ! self::keepsQuery($moved, $fetched)) {
            return null;
        }

        return $moved;
    }

    /** The normalized URL to store, or null to keep the one stored. */
    private static function for(Shop $shop, string $finalUrl): ?string
    {
        $moved = self::target($shop->url, $finalUrl);

        if ($moved === null) {
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
     * The same parameters, repeated keys included, and no others. A move that
     * drops `?variant=` lands on the page default, and one that adds it picks
     * a variant of its own; storing either would link a variant other than
     * the one priced.
     */
    private static function keepsQuery(string $moved, string $stored): bool
    {
        $now = self::pairs($moved);
        $was = self::pairs($stored);
        sort($now);
        sort($was);

        return $now === $was;
    }

    /** @return list<string> */
    private static function pairs(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query === '' ? [] : explode('&', $query);
    }
}
