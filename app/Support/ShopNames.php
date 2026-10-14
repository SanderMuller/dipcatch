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
     * The name of the shop at a host, or null when DipCatch knows none. A
     * subdomain takes its shop's name: webwinkel.poiesz-supermarkten.nl is
     * Poiesz.
     */
    public static function of(string $host): ?string
    {
        $names = self::all();
        $host = mb_strtolower($host);

        while (str_contains($host, '.')) {
            if (isset($names[$host])) {
                return $names[$host];
            }

            $host = substr($host, strpos($host, '.') + 1);
        }

        return null;
    }

    /** The shop's name, or its host when DipCatch knows no name. */
    public static function label(string $host): string
    {
        return self::of($host) ?? $host;
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
