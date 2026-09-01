<?php declare(strict_types=1);

namespace App\Support;

/**
 * Decodes a page's Nuxt `__NUXT_DATA__` payload: devalue format, one flat
 * JSON array where every object value is an index into that same array.
 */
final readonly class NuxtData
{
    /**
     * A record's field: object values are indices into the flat array, so a
     * field is read by dereferencing that index.
     *
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    public static function value(array $data, array $record, string $key): mixed
    {
        $index = $record[$key] ?? null;

        return is_int($index) && array_key_exists($index, $data) ? $data[$index] : null;
    }

    /**
     * Every record carrying all `$keys` whose `$idKey` dereferences to
     * `$id`. A product page carries related products in the same payload
     * and with the same shape, so an id match is what makes a record ours.
     *
     * @param  list<mixed>  $data
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public static function recordsFor(array $data, array $keys, string $idKey, string $id): array
    {
        $records = [];

        foreach ($data as $element) {
            if (! is_array($element)) {
                continue;
            }

            foreach ($keys as $key) {
                if (! isset($element[$key])) {
                    continue 2;
                }
            }

            /** @var array<string, mixed> $element */
            $recordId = self::value($data, $element, $idKey);

            if ((is_string($recordId) || is_int($recordId)) && (string) $recordId === $id) {
                $records[] = $element;
            }
        }

        return $records;
    }

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
