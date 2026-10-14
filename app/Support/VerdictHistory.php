<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Closure;

/**
 * What {@see PriceVerdicts} needs from each shop's last 90 days, as scalars:
 * the cache store unserializes no objects. Only its 90-day window depends on
 * the time, and it may lag the read by up to the cache hour.
 *
 * @phpstan-type History array{
 *     newest: array{lastAt: int, price: numeric-string|null, isEvidence: bool}|null,
 *     stats: array{low: numeric-string, median: numeric-string, high: numeric-string, lowest: numeric-string, firstAt: int}|null,
 * }
 */
final class VerdictHistory
{
    private const int MIN_DAYS = 30;

    private const int MAX_GAP_SECONDS = 7 * 86_400;

    /**
     * From here on a check stores the shop's pack size. A null size before
     * it is a legacy row that never knew one; after it, a size unknown at the
     * time. The columns shipped in 482eef3 on 2026-10-13; the first midnight
     * after that deploy is safe, because a row with a stored size uses that
     * size whatever its date.
     */
    private const string SIZE_CUTOFF = '2026-10-14 00:00:00';

    /**
     * @param  list<Shop>  $shops
     * @return array<string, History>
     */
    public static function of(array $shops, CarbonImmutable $windowStart): array
    {
        $sizeCutoff = CarbonImmutable::parse(self::SIZE_CUTOFF, VerdictReading::DAY_TIMEZONE);
        [$days, $newest, $sizesAfterCutoff] = VerdictReading::read(array_map(static fn (Shop $shop): string => $shop->id, $shops), $windowStart, $sizeCutoff);
        $histories = [];

        foreach ($shops as $shop) {
            $histories[$shop->id] = self::history($newest[$shop->id] ?? null, $days[$shop->id] ?? [], self::sizeRule($shop, $sizeCutoff, $sizesAfterCutoff));
        }

        return $histories;
    }

    /**
     * No stats when the evidence cannot carry a verdict at any time.
     *
     * @param  list<VerdictReading>  $days  The shop's days of evidence, in any order.
     * @param  Closure(VerdictReading): bool  $sizeRule
     * @return History
     */
    private static function history(?VerdictReading $newest, array $days, Closure $sizeRule): array
    {
        if (! $newest instanceof VerdictReading) {
            return ['newest' => null, 'stats' => null];
        }

        $reading = ['lastAt' => $newest->lastAt, 'price' => $newest->price, 'isEvidence' => $newest->isEvidence];

        usort($days, static fn (VerdictReading $a, VerdictReading $b): int => $a->firstAt <=> $b->firstAt);

        $evidence = array_values(array_filter($days, static fn (VerdictReading $day): bool => $day->seller === $newest->seller && $sizeRule($day)));

        // The newest reading must itself pass every rule, so it ends the evidence.
        if (! $sizeRule($newest) || array_last($evidence)?->lastAt !== $newest->lastAt || ! self::isCovered($evidence)) {
            return ['newest' => $reading, 'stats' => null];
        }

        $daily = self::dailyLows($evidence);

        if (count($daily) < self::MIN_DAYS) {
            return ['newest' => $reading, 'stats' => null];
        }

        return ['newest' => $reading, 'stats' => [
            'low' => self::percentile($daily, 0.25),
            'median' => self::percentile($daily, 0.5),
            'high' => self::percentile($daily, 0.75),
            'lowest' => $daily[0],
            'firstAt' => $evidence[0]->firstAt,
        ]];
    }

    /**
     * Which readings were taken at today's pack size. A stored size must
     * match. A legacy day from before the cutoff counts while the first
     * reading after the cutoff, of any status, has today's size; with no
     * reading after the cutoff yet, nothing shows a change, so it counts. The
     * cutoff is a midnight in Amsterdam, so a day is wholly before or after it.
     *
     * @param  array<string, string|null>  $sizesAfterCutoff  Shop id => the size of its first reading after the cutoff.
     * @return Closure(VerdictReading): bool
     */
    private static function sizeRule(Shop $shop, CarbonImmutable $sizeCutoff, array $sizesAfterCutoff): Closure
    {
        $cutoff = $sizeCutoff->getTimestamp();
        $todaysSize = VerdictReading::sizeKey($shop->pack_quantity, $shop->pack_unit);
        $legacyCounts = ! array_key_exists($shop->id, $sizesAfterCutoff) || $sizesAfterCutoff[$shop->id] === $todaysSize;

        return static fn (VerdictReading $reading): bool => $reading->size === null && $reading->firstAt < $cutoff
            ? $legacyCounts
            : $reading->size === $todaysSize;
    }

    /**
     * No gap over 7 days between two evidence readings. The newest is fresh,
     * so the stretch to today needs no check of its own.
     *
     * @param  list<VerdictReading>  $evidence  Oldest first.
     */
    private static function isCovered(array $evidence): bool
    {
        $previous = null;

        foreach ($evidence as $day) {
            if ($previous !== null && $day->firstAt - $previous > self::MAX_GAP_SECONDS) {
                return false;
            }

            $previous = $day->lastAt;
        }

        return true;
    }

    /**
     * The lowest price of each calendar day, sorted low to high. A day
     * without a reading is left out, not filled in.
     *
     * @param  list<VerdictReading>  $evidence
     * @return list<numeric-string>
     */
    private static function dailyLows(array $evidence): array
    {
        $lows = [];

        foreach ($evidence as $day) {
            if ($day->price === null || $day->day === null) {
                continue;
            }

            if (! isset($lows[$day->day]) || bccomp($day->price, $lows[$day->day], 2) === -1) {
                $lows[$day->day] = $day->price;
            }
        }

        $lows = array_values($lows);
        usort($lows, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        return $lows;
    }

    /**
     * Linear interpolation between the closest ranks, as Postgres
     * `percentile_cont` does.
     *
     * @param  non-empty-list<numeric-string>  $sorted
     * @return numeric-string
     */
    private static function percentile(array $sorted, float $fraction): string
    {
        $position = $fraction * (count($sorted) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $weight = number_format($position - $lower, 4, '.', '');

        return bcadd($sorted[$lower], bcmul(bcsub($sorted[$upper], $sorted[$lower], 4), $weight, 4), 4);
    }
}
