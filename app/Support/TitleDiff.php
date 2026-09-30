<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Marks the words of one product title that the other title lacks, so "Mango"
 * stands out against "Vanille".
 *
 * A part of four or more letters found inside a longer word matches it, so the
 * Dutch compound "koffiebonen" does not stand out against "bonen".
 */
final class TitleDiff
{
    private const array UNITS = [
        'kilo' => 'kg', 'kilogram' => 'kg', 'kilos' => 'kg',
        'gram' => 'g', 'gr' => 'g', 'grams' => 'g',
        'liter' => 'l', 'litre' => 'l', 'ltr' => 'l', 'lt' => 'l',
        'milliliter' => 'ml', 'millilitre' => 'ml',
        'stuks' => 'st', 'stuk' => 'st', 'pcs' => 'st', 'pieces' => 'st',
    ];

    private const array IGNORED = ['de', 'het', 'een', 'en', 'met', 'van', 'voor', 'the', 'a', 'and', 'with', 'of', 'for', 'x'];

    /**
     * The title as its whitespace-separated words, each marked when it has a
     * part the other title does not have.
     *
     * @return list<array{text: string, differs: bool}>
     */
    public static function marks(string $title, string $other): array
    {
        $theirs = self::parts($other);
        $words = preg_split('/\s+/u', trim($title), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(static fn (string $word): array => [
            'text' => $word,
            'differs' => array_any(self::parts($word), static fn (string $part): bool => ! self::shares($part, $theirs)),
        ], $words);
    }

    /**
     * @param  list<string>  $theirs
     */
    private static function shares(string $part, array $theirs): bool
    {
        return array_any($theirs, static fn (string $their): bool => $their === $part
            || (min(strlen($their), strlen($part)) >= 4 && ! is_numeric($part) && (str_contains($part, $their) || str_contains($their, $part))));
    }

    /**
     * @return list<string>
     */
    private static function parts(string $text): array
    {
        // An apostrophe joins its word: "Lay's" and "Lays" are one name.
        $text = str_replace(["'", '’'], '', Str::lower(Str::ascii($text)));
        preg_match_all('/\p{L}+|\d+(?:[.,]\d+)?/u', $text, $matches);

        return array_values(array_filter(
            array_map(static fn (string $part): string => self::UNITS[$part] ?? str_replace(',', '.', $part), $matches[0]),
            static fn (string $part): bool => ! in_array($part, self::IGNORED, strict: true),
        ));
    }
}
