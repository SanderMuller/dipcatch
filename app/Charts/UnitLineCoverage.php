<?php declare(strict_types=1);

namespace App\Charts;

use Carbon\CarbonImmutable;

/**
 * The share of the charted time the per-unit line covers, from 0 to 1.
 *
 * A pack size that only appeared at the last check gives a per-unit line of a
 * few hours at the right edge: one dot on an empty chart. The product page
 * opens on the pack price until the line covers enough of the range to read.
 */
final class UnitLineCoverage
{
    /** Below this share the per-unit line is too short to open the chart on. */
    public const float READABLE = 0.25;

    /**
     * @param  list<array<string, mixed>>  $rows  Rows from {@see PriceHistoryFluxChart}, oldest first.
     */
    public static function of(array $rows): float
    {
        $start = self::timestamp(array_first($rows));
        $end = self::timestamp(array_last($rows));
        $hasUnit = array_any($rows, fn (array $row): bool => is_float($row['unit'] ?? null));

        if ($start === null || $end === null || ! $hasUnit) {
            return 0.0;
        }

        // One moment is one dot on either line: nothing to prefer the unit line for.
        if ($end <= $start) {
            return 0.0;
        }

        // Each row holds until the next one, so a gap in the middle of the
        // line counts as uncovered, not only the time before it began.
        $covered = 0;

        foreach (array_values($rows) as $index => $row) {
            $from = self::timestamp($row);
            $until = self::timestamp($rows[$index + 1] ?? null);

            if (is_float($row['unit'] ?? null) && $from !== null && $until !== null) {
                $covered += $until - $from;
            }
        }

        return $covered / ($end - $start);
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private static function timestamp(?array $row): ?int
    {
        $date = $row['date'] ?? null;

        return is_string($date) ? CarbonImmutable::parse($date)->getTimestamp() : null;
    }
}
