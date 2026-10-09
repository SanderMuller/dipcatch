<?php declare(strict_types=1);

namespace App\Support;

/**
 * Shop names as people say them, from `site.shop_names`: a search for
 * "albert heijn" finds ah.nl.
 */
final readonly class ShopNames
{
    /**
     * @return array<string, string> name keyed by host
     */
    public static function all(): array
    {
        $names = config('site.shop_names');

        if (! is_array($names)) {
            return [];
        }

        return array_filter(
            $names,
            static fn (mixed $name, mixed $host): bool => is_string($host) && is_string($name) && $name !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    public static function hostsMatching(string $term): array
    {
        $term = mb_strtolower($term);

        return array_keys(array_filter(self::all(), static fn (string $name): bool => str_contains(mb_strtolower($name), $term)));
    }
}
