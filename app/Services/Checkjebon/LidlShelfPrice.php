<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\Models\CheckjebonPrice;

/**
 * The shelf price of a Lidl grocery product, from the checkjebon dataset.
 * lidl.nl states no price for its grocery range: the JSON-LD offer has none
 * and the Nuxt price record has none (19 of 19 food pages sampled
 * 2026-10-05). The dataset's Lidl rows are keyed by the boodschaapje id,
 * which is the IAN a lidl.nl product record lists under `ians`.
 *
 * That key named a different product for 2 of 18 pages sampled ("Witbrood"
 * read "Witte mini puntjes"), so a row only counts when its name agrees
 * with the page title. A wrong price stored without a warning is worse
 * than no price.
 */
final readonly class LidlShelfPrice
{
    /** A shorter word ("bio", "za", "all") does not name the product. */
    private const int MIN_WORD = 4;

    /** The dataset abbreviates past this many letters ("Vaatwastabs"). */
    private const int ABBREVIATED_AFTER = 5;

    /**
     * A row older than this is a price the importer kept when upstream sent
     * none. Matches the suggestion catalogue's cutoff in SuggestShops.
     */
    private const int MAX_AGE_HOURS = 96;

    /**
     * The row for exactly one of the product's IANs whose name agrees with
     * the page title, or null when none does or when more than one does.
     *
     * @param  list<string>  $ians
     */
    public function rowFor(array $ians, string $title): ?CheckjebonPrice
    {
        if ($ians === []) {
            return null;
        }

        $rows = CheckjebonPrice::query()
            ->where('supermarket', 'lidl')
            ->whereIn('external_id', $ians)
            ->where('refreshed_at', '>=', now()->subHours(self::MAX_AGE_HOURS))
            ->get()
            ->filter(fn (CheckjebonPrice $row): bool => self::namesAgree($row->name, $title));

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /**
     * True when every word of the dataset name starts a word of the title,
     * read up to its abbreviation: "Chocolade noot&roz." agrees with
     * "Chocoladereep noot & rozijn", "Zalm gerookt" does not agree with
     * "Gerookte kipfilet". Numbers both names state must be the same, and a
     * name with no word long enough agrees with nothing.
     */
    public static function namesAgree(string $datasetName, string $title): bool
    {
        $numbers = self::numbers($datasetName);
        $titleNumbers = self::numbers($title);

        if ($numbers !== [] && $titleNumbers !== [] && $numbers !== $titleNumbers) {
            return false;
        }

        $titleWords = self::words($title);
        $words = array_filter(self::words($datasetName), fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD);

        return $words !== [] && array_all($words, fn (string $word): bool => array_any(
            $titleWords,
            fn (string $titleWord): bool => str_starts_with($titleWord, mb_substr($word, 0, self::ABBREVIATED_AFTER)),
        ));
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return list<string>
     */
    private static function numbers(string $name): array
    {
        preg_match_all('/\d+/', $name, $matches);
        $numbers = array_unique($matches[0]);
        sort($numbers);

        return $numbers;
    }
}
