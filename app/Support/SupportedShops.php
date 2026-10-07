<?php declare(strict_types=1);

namespace App\Support;

use App\PriceAdapters\Hosts\HostUrl;
use App\PriceAdapters\Hosts\StructuredDataHostAdapter;
use App\PriceAdapters\OwnsHosts;

/**
 * Shops with a dedicated adapter or data source: the host, the favicon, the
 * name people use and the slug its landing page lives at. Source:
 * `config/site.php`. `ShopPages` adds the copy on top of these.
 */
final readonly class SupportedShops
{
    /**
     * Every host with a shop landing page. A list that only links shops reads
     * this, or {@see self::highlights()}, rather than `ShopPages`, which builds
     * each page's copy.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function rows(): array
    {
        return self::fromHosts(config('site.supported_hosts'));
    }

    /**
     * Shops that refuse DipCatch's requests, for the list under the supported
     * ones. They have no landing page, so their slug links nowhere.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function unsupported(): array
    {
        return self::fromHosts(config('site.unsupported_hosts'));
    }

    /**
     * The shops in `site.highlight_hosts`. A host missing from
     * `supported_hosts` is skipped, so a shop dropped there disappears here
     * too.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function highlights(): array
    {
        return self::pick(config('site.highlight_hosts'));
    }

    /**
     * The supported shops with a reader that knows the shop's own pages: a
     * host adapter or a product API. Sorted by name, for the full list on
     * the shops page.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function withOwnReader(): array
    {
        return self::sortedByName(array_filter(self::rows(), static fn (array $row): bool => ! self::readsFromPageData($row['host'])));
    }

    /**
     * The shops DipCatch reads from the standard product data on the page,
     * with no reader of their own: the supported shops on the structured-data
     * chain, plus `site.generic_hosts`. Sorted by name.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    public static function readFromPage(): array
    {
        $supported = array_filter(self::rows(), static fn (array $row): bool => self::readsFromPageData($row['host']));

        return self::sortedByName([...$supported, ...self::fromHosts(config('site.generic_hosts'))]);
    }

    /**
     * The supported rows for a configured host list, in its order.
     *
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    private static function pick(mixed $hosts): array
    {
        $allowed = [];
        foreach (self::rows() as $row) {
            $allowed[$row['host']] = $row;
        }

        $rows = [];
        foreach (is_array($hosts) ? $hosts : [] as $host) {
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
     * Whether the adapter that owns the host only runs the generic
     * structured-data chain. A host no adapter owns is read by a product
     * API, so it has a reader of its own.
     */
    private static function readsFromPageData(string $host): bool
    {
        foreach (config()->array('dipcatch.adapters') as $class) {
            if (! is_string($class) || ! is_subclass_of($class, OwnsHosts::class)) {
                continue;
            }

            $adapter = app($class);

            if ($adapter instanceof OwnsHosts && HostUrl::matchesAny("https://{$host}/", $adapter->ownedHosts())) {
                return $adapter instanceof StructuredDataHostAdapter;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, array{host: string, favicon: string, name: string, slug: string}>  $rows
     * @return list<array{host: string, favicon: string, name: string, slug: string}>
     */
    private static function sortedByName(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

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
