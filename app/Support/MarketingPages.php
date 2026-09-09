<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * The public marketing pages, as the sitemap sees them.
 *
 * Each page exists in two representations: the bare URL is the canonical
 * English page and `?lang=nl` the canonical Dutch one, which is what the
 * `MarketingLocale` middleware and the hreflang links on the pages already
 * say. Keeping the list here means the sitemap, its test, and any later move
 * to `/nl` paths read one definition.
 */
final class MarketingPages
{
    /**
     * The indexable marketing pages, in sitemap order, as route name plus its
     * parameters.
     *
     * A pair rather than a bare name because the use-case pages all share one
     * route and differ only by slug, so a list of names cannot address them.
     *
     * `/register` is deliberately absent: it is crawlable but has nothing a
     * sitemap adds. Auth pages and `/p/{slug}` are `noindex` and never listed.
     *
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public static function routes(): array
    {
        $pages = [
            ['home', []],
            ['pricing', []],
            ['privacy', []],
        ];

        foreach (UseCases::slugs() as $slug) {
            $pages[] = ['use-case', ['slug' => $slug]];
        }

        $pages[] = ['shops', []];

        foreach (ShopPages::slugs() as $slug) {
            $pages[] = ['shop', ['slug' => $slug]];
        }

        return $pages;
    }

    /**
     * Every sitemap entry: one per page and locale, with the alternates that
     * mirror the page's own hreflang links.
     *
     * @return list<array{loc: string, lastmod: string|null, alternates: array<string, string>}>
     */
    public static function all(): array
    {
        $entries = [];

        foreach (self::routes() as [$name, $params]) {
            $bare = route($name, $params);
            $dutch = route($name, [...$params, 'lang' => 'nl']);

            $alternates = [
                'en' => $bare,
                'nl' => $dutch,
                'x-default' => $bare,
            ];

            $lastmod = self::lastModified($name);

            $entries[] = ['loc' => $bare, 'lastmod' => $lastmod, 'alternates' => $alternates];
            $entries[] = ['loc' => $dutch, 'lastmod' => $lastmod, 'alternates' => $alternates];
        }

        return $entries;
    }

    /**
     * The only page that carries a meaningful change date is the privacy
     * statement, which already publishes one to its readers.
     */
    private static function lastModified(string $route): ?string
    {
        if ($route !== 'privacy') {
            return null;
        }

        $updated = Config::get('site.privacy_updated_at');

        return is_string($updated) && $updated !== '' ? $updated : null;
    }
}
