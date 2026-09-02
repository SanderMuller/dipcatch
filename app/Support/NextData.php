<?php declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads a Next.js page's `__NEXT_DATA__` payload — plain JSON in a script
 * tag, unlike Nuxt's devalue array ({@see NuxtData}).
 */
final readonly class NextData
{
    /**
     * The decoded payload, or null when the page carries none. Keys are
     * whatever the page shipped, so the type stays as loose as the JSON.
     *
     * @return array<mixed, mixed>|null
     */
    public static function decode(string $html): ?array
    {
        $script = new Crawler($html)->filter('script#__NEXT_DATA__')->first();

        if ($script->count() === 0) {
            return null;
        }

        $decoded = json_decode($script->text(''), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A nested value by dotted path, e.g. `props.pageProps.apiData`.
     *
     * @param  array<mixed, mixed>  $data
     */
    public static function value(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
