<?php declare(strict_types=1);

namespace App\Support;

/**
 * Decodes a page's Nuxt `__NUXT_DATA__` payload: devalue format, one flat
 * JSON array where every object value is an index into that same array.
 */
final readonly class NuxtData
{
    /**
     * @return list<mixed>|null
     */
    public static function decode(string $html): ?array
    {
        if (preg_match('#<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m) !== 1) {
            return null;
        }

        $data = json_decode($m[1], associative: true);

        return is_array($data) && array_is_list($data) ? $data : null;
    }
}
