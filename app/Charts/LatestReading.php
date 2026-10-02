<?php declare(strict_types=1);

namespace App\Charts;

use Carbon\CarbonImmutable;

/** Where a price history line ends: today's value, and a fall that happened today. */
final class LatestReading
{
    private const string TIMEZONE = 'Europe/Amsterdam';

    /**
     * Today's value on one line, and the drop in whole percent when that
     * value is a fall that happened today (Amsterdam time, as every date in
     * the app reads), so the chart can say the jump at its right edge is
     * news rather than a glitch.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{value: float, dropToday: ?int}|null
     */
    public static function of(array $rows, string $field): ?array
    {
        $last = array_last($rows);
        $value = is_array($last) ? ($last[$field] ?? null) : null;

        if (! is_float($value)) {
            return null;
        }

        // The stamp where the line last moved, and where it was before.
        $changedAt = null;
        $before = null;

        for ($index = count($rows) - 1; $index > 0; $index--) {
            $previous = $rows[$index - 1][$field] ?? null;

            if ($previous !== ($rows[$index][$field] ?? null)) {
                $changedAt = is_string($rows[$index]['date'] ?? null) ? $rows[$index]['date'] : null;
                $before = is_float($previous) ? $previous : null;

                break;
            }
        }

        $dropToday = $changedAt !== null && $before !== null && $before > 0 && $value < $before
            && CarbonImmutable::parse($changedAt)->setTimezone(self::TIMEZONE)->isSameDay(now(self::TIMEZONE))
            ? (int) round((1 - $value / $before) * 100)
            : null;

        return ['value' => $value, 'dropToday' => $dropToday === 0 ? null : $dropToday];
    }

    /**
     * Puts a `now` (and `nowUnit`) point on the newest row, which the chart
     * draws as the end of the line.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{price: ?array{value: float, dropToday: ?int}, unit: ?array{value: float, dropToday: ?int}}  $latest
     * @return list<array<string, mixed>>
     */
    public static function marked(array $rows, array $latest): array
    {
        $last = array_key_last($rows);

        if ($last === null) {
            return $rows;
        }

        if ($latest['price'] !== null) {
            $rows[$last]['now'] = $latest['price']['value'];
        }

        if ($latest['unit'] !== null) {
            $rows[$last]['nowUnit'] = $latest['unit']['value'];
        }

        return $rows;
    }
}
