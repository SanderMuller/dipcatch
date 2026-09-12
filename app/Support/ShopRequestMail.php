<?php declare(strict_types=1);

namespace App\Support;

/**
 * A prefilled mailto so someone can ask for a shop reader.
 *
 * Returns null when `site.contact_email` is empty, matching the footer
 * Contact link: an empty mailto is worse than no link.
 */
final class ShopRequestMail
{
    public static function href(?string $host = null, ?string $productUrl = null): ?string
    {
        $email = config('site.contact_email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        $url = self::httpUrl($productUrl);
        $shop = self::host($host) ?? self::hostFromUrl($url);

        $subject = $shop === null
            ? __('Request a shop on DipCatch')
            : __('Request :shop on DipCatch', ['shop' => $shop]);

        $lines = [
            __('Paste a product URL from the shop. Most shops already work. A reader of its own is written when a generic read is not enough.'),
            '',
            $url === null
                ? __('Product URL:')
                : __('Product URL: :url', ['url' => $url]),
        ];

        if ($shop !== null) {
            $lines[] = __('Shop: :shop', ['shop' => $shop]);
        }

        return 'mailto:' . $email
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode(implode("\n", $lines));
    }

    private static function host(?string $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(trim($host));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        if (! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    private static function httpUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > 800) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], strict: true)) {
            return null;
        }

        if (self::host(is_string($host) ? $host : null) === null) {
            return null;
        }

        return $url;
    }

    private static function hostFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? self::host($host) : null;
    }
}
