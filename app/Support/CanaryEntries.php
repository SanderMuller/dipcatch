<?php declare(strict_types=1);

namespace App\Support;

use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\UserSelectorAdapter;
use Illuminate\Support\Facades\Config;

/**
 * Reads the adapter canary configuration.
 *
 * The command and the health check both need the same two answers — which
 * adapters have a canary URL, and which adapters ought to have one — and a
 * second copy of either would drift the first time one is edited.
 */
final class CanaryEntries
{
    /**
     * Adapter key => canary URL, for every entry that carries a URL. An empty
     * entry is not configured: the health check reports it as uncovered
     * rather than the command pretending to watch it.
     *
     * @return array<string, string>
     */
    public static function configured(): array
    {
        $configured = [];

        foreach (Config::array('canary.adapters') as $adapter => $url) {
            if (! is_string($adapter) || ! is_string($url) || $url === '') {
                continue;
            }

            $configured[$adapter] = $url;
        }

        return $configured;
    }

    /**
     * The adapters a canary entry is expected for: the host-specific ones in
     * the resolution chain. The generic adapters read no particular shop, so
     * they have nothing to rot against — and neither does
     * `UserSelectorAdapter`, which implements the same marker interface but
     * reads whatever CSS selector a user typed, on any host at all.
     *
     * @return list<string>
     */
    public static function hostAdapterKeys(): array
    {
        $keys = [];

        foreach (Config::array('dipcatch.adapters') as $class) {
            if (! is_string($class) || ! is_subclass_of($class, HostSpecificAdapter::class)) {
                continue;
            }

            if ($class === UserSelectorAdapter::class) {
                continue;
            }

            $adapter = app($class);

            if (! $adapter instanceof ShopAdapter) {
                continue;
            }

            $keys[] = $adapter->key();
        }

        return $keys;
    }
}
