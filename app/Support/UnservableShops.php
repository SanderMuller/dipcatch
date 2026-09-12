<?php declare(strict_types=1);

namespace App\Support;

/**
 * Shops whose product pages never carry a price the server can read, so a
 * probe is refused before it fetches anything.
 *
 * These are not blocked or rate-limited — the HTML simply holds no price.
 * The page is an app shell that loads its data from an endpoint scoped to
 * the visitor's own browser session, so reaching it would mean impersonating
 * that session rather than reading a public page. Offering the manual
 * selector flow here wastes the user's time: there is no markup to select.
 */
final class UnservableShops
{
    /**
     * Normalized host → why it cannot be served.
     *
     * @var array<string, string>
     */
    private const array HOSTS = [
        // OutSystems SPA: the PDP fetches its data from a POST endpoint that
        // rejects any request without the app's per-session CSRF token
        // ("Invalid Login"), even a byte-identical replay (verified
        // 2026-09-01). The served HTML is a 7 KB shell.
        'plus.nl' => 'plus_spa',
    ];

    public static function reasonFor(string $host): ?string
    {
        $host = UrlNormalizer::normalizeHost($host);

        foreach (self::HOSTS as $unservable => $reason) {
            if ($host === $unservable || str_ends_with($host, '.' . $unservable)) {
                return $reason;
            }
        }

        return null;
    }
}
