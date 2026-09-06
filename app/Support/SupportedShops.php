<?php declare(strict_types=1);

namespace App\Support;

/**
 * Shops with a dedicated adapter or data source, as favicon + host rows for
 * the homepage and the first-run dashboard. Source: `config/site.php`.
 */
final readonly class SupportedShops
{
    /**
     * @return list<array{host: string, favicon: string, name: string}>
     */
    public static function rows(): array
    {
        $hosts = config('site.supported_hosts');

        $rows = [];
        foreach (is_array($hosts) ? $hosts : [] as $host) {
            if (! is_string($host) || $host === '') {
                continue;
            }

            $rows[] = [
                'host' => $host,
                'favicon' => Favicon::url($host, 32),
                'name' => self::name($host),
            ];
        }

        return $rows;
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
