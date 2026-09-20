<?php declare(strict_types=1);

namespace App\Support;

/**
 * Shops with a dedicated adapter or data source: the host, the favicon, the
 * name people use and the slug its landing page lives at. Source:
 * `config/site.php`. `ShopPages` adds the copy on top of these.
 */
final readonly class SupportedShops
{
    /**
     * Every host with a shop landing page.
     *
     * Every list that only links shops reads this rather than `ShopPages`,
     * which builds each page's copy.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function rows(): array
    {
        return self::fromHosts(config('site.supported_hosts'));
    }

    /**
     * The short "Works with" row on the homepage. Hosts missing from
     * `supported_hosts` are skipped, so a shop dropped there disappears here too.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function homepage(): array
    {
        $allowed = [];
        foreach (self::rows() as $row) {
            $allowed[$row['host']] = $row;
        }

        $featured = config('site.homepage_hosts');
        $rows = [];
        foreach (is_array($featured) ? $featured : [] as $host) {
            if (is_string($host) && isset($allowed[$host])) {
                $rows[] = $allowed[$host];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    private static function fromHosts(mixed $hosts): array
    {
        $rows = [];
        foreach (is_array($hosts) ? $hosts : [] as $host) {
            if (! is_string($host) || $host === '') {
                continue;
            }

            $rows[] = [
                'host' => $host,
                'favicon' => Favicon::url($host, 32),
                'name' => self::name($host),
                'slug' => self::slug($host),
            ];
        }

        return $rows;
    }

    /**
     * A host makes a URL-safe slug by swapping its dots: `ah.nl` becomes
     * `ah-nl`. It keeps the shop recognisable in the URL. Never reversed to
     * recover the host — a host containing a hyphen would not round-trip.
     */
    private static function slug(string $host): string
    {
        return str_replace('.', '-', $host);
    }

    /**
     * The shop's name as people say it, or the host when nobody has named it.
     */
    private static function name(string $host): string
    {
        $names = config('site.shop_names');

        if (! is_array($names)) {
            return $host;
        }

        $name = $names[$host] ?? null;

        return is_string($name) && $name !== '' ? $name : $host;
    }
}
