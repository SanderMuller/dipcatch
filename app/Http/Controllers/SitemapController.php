<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MarketingPages;
use Illuminate\Http\Response;

/**
 * XML sitemap for the public marketing pages.
 *
 * The route drops the session middleware, so the response carries no
 * `Set-Cookie` and Cloudflare can honour the `Cache-Control` header.
 */
final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];

        foreach (MarketingPages::all() as $entry) {
            $lines[] = '    <url>';
            $lines[] = '        <loc>' . e($entry['loc']) . '</loc>';

            if ($entry['lastmod'] !== null) {
                $lines[] = '        <lastmod>' . e($entry['lastmod']) . '</lastmod>';
            }

            foreach ($entry['alternates'] as $hreflang => $href) {
                $lines[] = '        <xhtml:link rel="alternate" hreflang="' . e($hreflang) . '" href="' . e($href) . '"/>';
            }

            $lines[] = '    </url>';
        }

        $lines[] = '</urlset>';

        return response(implode("\n", $lines) . "\n")
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
