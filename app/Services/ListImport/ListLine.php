<?php declare(strict_types=1);

namespace App\Services\ListImport;

use App\Support\PackSize;

/**
 * One line of a pasted receipt or shopping list, reduced to the words that
 * name the product. A receipt line carries a count and a price around the
 * name ("2 X AH HALFV MELK 1,5L   2,38"); both go, and a size in the line
 * is kept apart so it can score the pack.
 */
final readonly class ListLine
{
    /**
     * @param  list<string>  $words  Lowercase ASCII, without the size.
     */
    private function __construct(
        public string $text,
        public array $words,
        public ?PackSize $size,
        public ?string $price = null,
        public string $raw = '',
    ) {}

    public static function of(string $raw): ?self
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        // A trailing amount of money, with or without a euro sign, and a
        // leading count: "2 X", "2x", "2 ". The amount is kept for Jev: it
        // tells a 250 g pack from a 500 g one.
        $price = preg_match('/(\d+[.,]\d{2})\s*[a-z]?$/iu', $text, $match) === 1 ? str_replace(',', '.', $match[1]) : null;
        $raw = $text;
        $text = preg_replace('/\s+[-€]?\s*\d+[.,]\d{2}\s*[a-z]?$/iu', '', $text) ?? $text;
        $text = preg_replace('/^\d+\s*[x×]?\s+/iu', '', $text) ?? $text;

        $size = PackSize::parse($text);
        $withoutSize = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:kg|g|gr|gram|l|ltr|liter|ml|cl|st|stuks)\b/iu', ' ', $text) ?? $text;

        $words = array_values(array_filter(
            explode(' ', CatalogueCandidates::normalise($withoutSize)),
            static fn (string $word): bool => mb_strlen($word) >= 2 && ! ctype_digit($word),
        ));

        return $words === [] ? null : new self($text, $words, $size, $price, $raw);
    }
}
