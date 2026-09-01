<?php declare(strict_types=1);

namespace App\Support;

/**
 * Shops with a dedicated adapter or data source, as favicon + host rows for
 * the homepage and the first-run dashboard. Source: `config/site.php`.
 */
final readonly class SupportedShops
{
    /**
     * @return list<array{host: string, favicon: string}>
     */
    public static function rows(): array
    {
        $hosts = config('site.supported_hosts');

        $rows = [];
        foreach (is_array($hosts) ? $hosts : [] as $host) {
            if (! is_string($host) || $host === '') {
                continue;
            }

            $rows[] = ['host' => $host, 'favicon' => Favicon::url($host, 32)];
        }

        return $rows;
    }
}
