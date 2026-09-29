<?php declare(strict_types=1);

namespace App\PriceAdapters;

use JsonException;

/**
 * JSON a page embeds in a script as a JavaScript literal, such as
 * `var meta = {…};`, where no tag or attribute marks where it ends.
 */
final readonly class EmbeddedJson
{
    /**
     * The object that opens at `$offset`, cut at its own closing brace. A
     * regex cannot find that brace: a string inside may hold `};`.
     *
     * @return array<mixed>|null
     */
    public static function objectAt(string $html, int $offset): ?array
    {
        if (($html[$offset] ?? '') !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $length = strlen($html);

        for ($i = $offset; $i < $length; $i++) {
            $char = $html[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return self::decode(substr($html, $offset, $i - $offset + 1));
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    public static function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
