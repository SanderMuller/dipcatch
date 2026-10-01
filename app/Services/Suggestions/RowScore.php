<?php declare(strict_types=1);

namespace App\Services\Suggestions;

use App\Actions\Suggestions\SuggestShops;
use App\Models\CheckjebonPrice;

/** How well a catalogue row matches a product's queries, for {@see SuggestShops}. */
final class RowScore
{
    /**
     * Taken off the overlap of a row whose pack size differs from the one
     * it is compared against, in the same unit. The size is two of about
     * eight tokens, so a wrong size alone barely moved the overlap: a 300 g
     * Milka Mmmax bar scored 0.556 against a 100 g Milka bar and was offered.
     * With this, a row of another size needs about 0.65 on its name alone.
     * The same name in a bigger pack still reaches that (0.71 for a
     * four-word name), since per-kilo prices compare across pack sizes.
     */
    private const float OTHER_SIZE_PENALTY = 0.1;

    /**
     * The row's score (its best name overlap, or 1.0 on a barcode match),
     * the query its name matched best, and whether it is in that query's
     * pack size.
     *
     * @param  list<QueryTokens>  $queries
     * @param  list<string>  $gtins
     * @return array{0: float, 1: int, 2: bool}
     */
    public static function of(CheckjebonPrice $row, array $queries, array $gtins): array
    {
        $candidate = QueryTokens::ofCatalogueRow($row->name, $row->size);
        $best = [0.0, 0, true];

        foreach ($candidate->isEmpty() ? [] : $queries as $index => $query) {
            if (! $query->sameVariantAs($candidate)) {
                continue;
            }

            $otherSize = $query->hasOtherSizeThan($candidate);
            $overlap = $query->overlapWith($candidate) - ($otherSize ? self::OTHER_SIZE_PENALTY : 0.0);

            if ($overlap > $best[0]) {
                $best = [$overlap, $index, ! $otherSize];
            }
        }

        // The same barcode as a tracked shop is the same article, whatever
        // the name says; only the pack the name matched best is kept.
        if (is_string($row->ean) && in_array($row->ean, $gtins, strict: true)) {
            $best[0] = 1.0;
        }

        return $best;
    }
}
