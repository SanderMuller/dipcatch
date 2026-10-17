<?php declare(strict_types=1);

namespace App\Support;

/**
 * Matches a search on letters, not on their accents: "andrelon" finds
 * "Andrélon droogshampoo", and "crème" finds "Creme brulee". People type
 * a brand without its accent, and shops spell the same brand both ways.
 *
 * The PHP side and the SQL side fold with one map, so the term and the
 * column agree. The SQL side also maps the capitals, because `LOWER()`
 * leaves "É" alone under a `C` database locale. PostgreSQL's `translate()` swaps one character for
 * one, so letters that fold to two (ß, æ, œ) are left as they are.
 */
final readonly class SearchText
{
    /** @var array<string, string> */
    private const array FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'š' => 's', 'ś' => 's', 'ş' => 's',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'ł' => 'l', 'ř' => 'r', 'ť' => 't', 'ď' => 'd', 'ğ' => 'g',
    ];

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), self::FOLD);
    }

    /**
     * The column folded as {@see fold()} folds a term. Pass {@see bindings()}
     * for its two placeholders, before the term's own.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function column(string $column): string
    {
        return "translate(LOWER({$column}), ?, ?)";
    }

    /** @return list<string> */
    public static function bindings(): array
    {
        $from = implode('', array_keys(self::FOLD));
        $to = implode('', self::FOLD);

        return [$from . mb_strtoupper($from), $to . $to];
    }
}
