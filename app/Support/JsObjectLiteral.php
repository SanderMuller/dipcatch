<?php declare(strict_types=1);

namespace App\Support;

/**
 * Reads fields out of a minified JavaScript object literal — the shape a
 * server-rendered Nuxt 2 page inlines in `window.__NUXT__`, which is JS
 * rather than JSON and so cannot be decoded.
 *
 * Only the object's own fields are returned: a nested promotion or
 * unit-price object carries keys of the same name, and reading one of those
 * as the product's own value is how a wrong price reaches a price alert.
 */
final class JsObjectLiteral
{
    /**
     * The object body that starts after `$marker`, cut at its own closing
     * brace. `$maxBytes` caps the scan for a page that never closes it.
     */
    public static function after(string $source, string $marker, int $maxBytes): ?string
    {
        $start = strpos($source, $marker);

        if ($start === false) {
            return null;
        }

        $offset = $start + strlen($marker);
        $depth = 1;
        $limit = min(strlen($source), $offset + $maxBytes);

        for ($i = $offset; $i < $limit; $i++) {
            $char = $source[$i];

            if ($char === '"') {
                $i = self::endOfString($source, $i) - 1;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($source, $offset, $i - $offset);
            }
        }

        return null;
    }

    /**
     * The object's own `key => raw value` pairs, values unparsed.
     *
     * @return array<string, string>
     */
    public static function fields(string $object): array
    {
        $fields = [];
        $length = strlen($object);
        $depth = 0;
        $i = 0;

        while ($i < $length) {
            $char = $object[$i];

            if ($char === '"') {
                $i = self::endOfString($object, $i);

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
                $i++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $i++;

                continue;
            }

            if ($depth === 0 && preg_match('/\G([A-Za-z_$][\w$]*):/', $object, $m, 0, $i) === 1) {
                $valueStart = $i + strlen($m[0]);
                $fields[$m[1]] ??= self::valueAt($object, $valueStart);
                $i = $valueStart;

                continue;
            }

            $i++;
        }

        return $fields;
    }

    /**
     * A field's value when it is a literal: a quoted string, or a plain
     * decimal number. An identifier (a value the minifier hoisted into an
     * IIFE parameter) and a numeric form this parser does not read —
     * `2.39e1`, `239n` — both yield null, because a wrong value is worse
     * than a missing one.
     *
     * @param  array<string, string>  $fields
     */
    public static function literal(array $fields, string $field): ?string
    {
        $raw = trim($fields[$field] ?? '');

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '"')) {
            return preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $raw, $m) === 1 ? stripcslashes($m[1]) : null;
        }

        return preg_match('/^\d+(?:\.\d+)?$/', $raw) === 1 ? $raw : null;
    }

    /**
     * The raw token starting at `$offset`: a quoted string, or everything
     * before the next delimiter.
     */
    private static function valueAt(string $object, int $offset): string
    {
        if (($object[$offset] ?? '') === '"') {
            return substr($object, $offset, self::endOfString($object, $offset) - $offset);
        }

        $length = strlen($object);
        $end = $offset;

        while ($end < $length && ! str_contains(',}]', $object[$end])) {
            $end++;
        }

        return substr($object, $offset, $end - $offset);
    }

    /** Index just past the string literal that starts at `$offset`. */
    private static function endOfString(string $object, int $offset): int
    {
        $length = strlen($object);

        for ($i = $offset + 1; $i < $length; $i++) {
            if ($object[$i] === '\\') {
                $i++;

                continue;
            }

            if ($object[$i] === '"') {
                return $i + 1;
            }
        }

        return $length;
    }
}
