<?php declare(strict_types=1);

namespace App\Support;

/**
 * How many items a title counts, in the shapes shops write it: `12 x 55 g`,
 * `330ml x 24`, `12 stuks`, `6-pack` and a named set ("set van 4", "pack of
 * 24", "lot de 6"). Apart from {@see PackSize}, which turns text into a
 * size, because a count says how many of something a pack holds.
 */
final readonly class ItemCount
{
    /**
     * A count a title names as a set or a pack of so many, in the languages
     * of the shops DipCatch reads: "set van 4", "pack of 6", "lot de 4",
     * "4er-Set", "confezione da 6". Unlike a bare piece count, the words say
     * the size beside it is one item's, wherever the count stands.
     *
     * No "4-delig" or "3-teilig": a three-piece pan set holds three
     * different pans, not three of one size.
     */
    private const string DASH_PACK_PATTERN = '/(\d+(?:[.,]\d+)?)-pack\b/i';

    private const array SET_COUNT_PATTERNS = [
        '/\b(?:set|pak|doos|verpakking|pack|box|case|lot|coffret|bo[iî]te|juego|confezione|packung)\s+(?:van|of|de|di|da|mit)\s+(\d+)\b/iu',
        '/\b(\d+)er[\s-]?(?:set|pack|packung)\b/iu',
    ];

    /**
     * How many items a title counts, whichever way it writes them: `12 x 55 g`,
     * `55 g x 12` and a piece count (`12st ...`, `12 stuks ...`) all give 12.
     *
     * Null when the title counts nothing, or counts more than one thing.
     */
    public static function in(string $title): ?float
    {
        if (preg_match_all(PackSize::crossFormPattern(), $title, $matches, PREG_SET_ORDER) === 1) {
            return self::number($matches[0][1]);
        }

        if (preg_match_all(PackSize::sizeFirstCrossFormPattern(), $title, $matches, PREG_SET_ORDER) === 1) {
            return self::number($matches[0][3]);
        }

        preg_match_all(PackSize::pieceCountPattern(), $title, $pieceMatches);
        $pieces = array_values(array_unique(array_map(self::number(...), $pieceMatches[1])));

        return count($pieces) === 1 ? $pieces[0] : null;
    }

    /**
     * The count a shop's second size takes from its title: {@see in()}, a named
     * set, or `12-pack`. Not for multiplying a stated size: "Bier 24-pack 7,92 l"
     * states the whole pack beside its count, and only the count is news.
     */
    public static function ofSecondSize(string $title): ?float
    {
        return self::in($title)
            ?? self::setIn($title)
            ?? (preg_match_all(self::DASH_PACK_PATTERN, $title, $matches) === 1 ? self::number($matches[1][0]) : null);
    }

    /**
     * The item size a counted title states, `<count> x <size>` or `<size> x
     * <count>`, so a caller can tell it apart from the size of the whole pack.
     */
    public static function crossFormItemIn(string $title): ?PackSize
    {
        if (preg_match_all(PackSize::crossFormPattern(), $title, $matches, PREG_SET_ORDER) === 1) {
            return PackSize::parse($matches[0][2] . ' ' . $matches[0][3]);
        }

        if (preg_match_all(PackSize::sizeFirstCrossFormPattern(), $title, $matches, PREG_SET_ORDER) === 1) {
            return PackSize::parse($matches[0][1] . ' ' . $matches[0][2]);
        }

        return null;
    }

    /**
     * How many items a "set of 4" counts. Null unless the title names one
     * such count, and never beside a cross form, which counts them already.
     */
    public static function setIn(string $title): ?float
    {
        if (PackSize::hasCrossForm($title)) {
            return null;
        }

        $counts = [];

        foreach (self::SET_COUNT_PATTERNS as $pattern) {
            preg_match_all($pattern, $title, $matches);
            array_push($counts, ...array_map(floatval(...), $matches[1]));
        }

        $counts = array_unique($counts);

        return count($counts) === 1 && $counts[0] > 1 ? $counts[0] : null;
    }

    private static function number(string $raw): float
    {
        return (float) str_replace(',', '.', $raw);
    }
}
