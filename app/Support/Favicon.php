<?php declare(strict_types=1);

namespace App\Support;

/**
 * Favicon URLs for shop hosts, served by Google's s2 favicon proxy — no
 * fetching or storage on our side, and the proxy falls back to a neutral
 * globe glyph for hosts without an icon.
 */
final readonly class Favicon
{
    public static function url(string $host, int $size = 64): string
    {
        return 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=' . $size;
    }

    /**
     * Favicon + host as an inline HTML fragment for Filament columns and
     * entries. The host is escaped here; callers wrap the result in an
     * HtmlString.
     */
    public static function html(string $host): string
    {
        // Inline styles, not Tailwind classes — panel themes purge utility
        // classes that only appear in PHP strings (the admin panel renders
        // them unstyled, blowing the icon up to natural size).
        return '<span style="display:inline-flex;align-items:center;gap:0.375rem">'
            . '<img src="' . e(self::url($host)) . '" alt="" loading="lazy" style="width:1rem;height:1rem;flex:none;border-radius:0.25rem" />'
            . '<span>' . e($host) . '</span>'
            . '</span>';
    }
}
