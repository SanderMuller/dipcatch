<?php declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The link the app opens for a shop page: the shop's affiliate link where
 * DipCatch takes part in its program, the page itself otherwise. Only for
 * links shown in the app: e-mails keep the plain page, and so does an
 * account excluded from affiliate links, such as the owner's own, whose
 * purchases a program does not pay for.
 */
final class AffiliateLink
{
    public static function for(string $url, ?User $viewer = null): string
    {
        if (self::excluded($viewer)) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return match (is_string($host) ? UrlNormalizer::normalizeHost($host) : null) {
            'bol.com' => self::bol($url) ?? $url,
            'amazon.nl' => self::amazon($url) ?? $url,
            default => $url,
        };
    }

    /**
     * The shops whose links are affiliate links for this viewer, as the
     * disclosure names them.
     *
     * @return list<string>
     */
    public static function shops(?User $viewer = null): array
    {
        if (self::excluded($viewer)) {
            return [];
        }

        $shops = [];

        if (self::bolSiteId() !== null) {
            $shops[] = 'bol.com';
        }

        if (self::amazonTag() !== null) {
            $shops[] = 'Amazon';
        }

        return $shops;
    }

    /** `sponsored` for a link that earns a commission, as search engines ask. */
    public static function rel(string $url, ?User $viewer = null): string
    {
        return self::for($url, $viewer) === $url ? 'noopener noreferrer' : 'sponsored noopener noreferrer';
    }

    private static function excluded(?User $viewer): bool
    {
        $viewer ??= auth()->user();

        return $viewer instanceof User && $viewer->affiliate_links_excluded;
    }

    /** bol.com's partner click link around the page, with the site id from the partner account. */
    private static function bol(string $url): ?string
    {
        $siteId = self::bolSiteId();

        return $siteId === null ? null : 'https://partner.bol.com/click/click?' . http_build_query([
            'p' => '1',
            't' => 'url',
            's' => $siteId,
            'f' => 'TXL',
            'url' => $url,
            'name' => 'DipCatch',
        ]);
    }

    /**
     * The amazon.nl page itself with the partner tag in its query, which
     * Amazon requires to stay visible: no redirect, no shortener.
     */
    private static function amazon(string $url): ?string
    {
        $tag = self::amazonTag();

        if ($tag === null) {
            return null;
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query['tag'] = $tag;

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.amazon.nl') . ($parts['path'] ?? '/')
            . '?' . http_build_query($query)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private static function bolSiteId(): ?string
    {
        $siteId = config('services.bol.affiliate.site_id');

        return is_string($siteId) && $siteId !== '' ? $siteId : null;
    }

    private static function amazonTag(): ?string
    {
        $tag = config('services.amazon.associate_tag');

        return is_string($tag) && $tag !== '' ? $tag : null;
    }
}
