<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PriceVerdict;
use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * Says whether a shop's price today is good, normal or high against that
 * shop's own last 90 days. It describes the past and never forecasts. See
 * specs/buy-now-or-wait.md, section 2.
 *
 * A shop with too little evidence, or whose stored price no fresh reading
 * backs, gets nothing at all.
 */
final class PriceVerdicts
{
    private const int WINDOW_DAYS = 90;

    private const int MIN_DAYS = 30;

    private const int MAX_GAP_SECONDS = 7 * 86_400;

    private const int FRESH_SECONDS = 3 * 86_400;

    /** How late the first reading may come for "Lowest in 90 days" to cover the window. */
    private const int COVERAGE_SLACK_SECONDS = 3 * 86_400;

    /** The 5% margin from the median, as a factor in percent. */
    private const string GOOD_FACTOR = '95';

    private const string HIGH_FACTOR = '105';

    private const string DAY_TIMEZONE = 'Europe/Amsterdam';

    /**
     * From here on a check stores the shop's pack size. A null size before
     * it is a legacy row that never knew one; after it, a size unknown at the
     * time. The columns shipped in 482eef3 on 2026-10-13; the first midnight
     * after that deploy is safe, because a row with a stored size uses that
     * size whatever its date.
     */
    private const string SIZE_CUTOFF = '2026-10-14 00:00:00';

    /**
     * The verdict of the shop each product's headline names, keyed by product
     * id. A product whose headline falls back to a shop nobody can buy at now
     * gets none. The products need their shops loaded.
     *
     * @param  iterable<Product>  $products
     * @return array<string, ShopPriceVerdict>
     */
    public static function forProducts(iterable $products): array
    {
        $leaders = [];

        foreach ($products as $product) {
            $shop = HeadlinePrice::of($product)->buyableShop();

            if ($shop instanceof Shop) {
                $leaders[$product->id] = $shop;
            }
        }

        $verdicts = self::forShops(new Collection(array_values($leaders)));
        $results = [];

        foreach ($leaders as $productId => $shop) {
            if (isset($verdicts[$shop->id])) {
                $results[$productId] = $verdicts[$shop->id];
            }
        }

        return $results;
    }

    /**
     * Whether the shop's price now is its lowest in 90 days, for an alert to
     * store when it fires.
     */
    public static function isLowestIn90Days(Shop $shop): bool
    {
        return (self::forShops(new Collection([$shop]))[$shop->id] ?? null)?->lowestIn90Days === true;
    }

    /**
     * One verdict per shop that has one, from two queries whatever the number
     * of shops: see {@see VerdictReading::read()}.
     *
     * @param  Collection<int, Shop>  $shops
     * @return array<string, ShopPriceVerdict>
     */
    public static function forShops(Collection $shops): array
    {
        $candidates = $shops->filter(static fn (Shop $shop): bool => ! $shop->isReference() && is_numeric($shop->current_price));

        if ($candidates->isEmpty()) {
            return [];
        }

        $now = CarbonImmutable::now();
        $windowStart = $now->subDays(self::WINDOW_DAYS);
        [$days, $newest] = VerdictReading::read($candidates->map(static fn (Shop $shop): string => $shop->id)->values()->all(), $windowStart);

        $results = [];

        foreach ($candidates as $shop) {
            $verdict = isset($newest[$shop->id])
                ? self::verdict($shop, $newest[$shop->id], $days[$shop->id] ?? [], $windowStart, $now)
                : null;

            if ($verdict instanceof ShopPriceVerdict) {
                $results[$shop->id] = $verdict;
            }
        }

        return $results;
    }

    /**
     * @param  list<VerdictReading>  $days  The shop's days of evidence, in any order.
     */
    private static function verdict(Shop $shop, VerdictReading $newest, array $days, CarbonImmutable $windowStart, CarbonImmutable $now): ?ShopPriceVerdict
    {
        /** @var numeric-string $today */
        $today = (string) $shop->current_price;

        if (! self::backsTodaysPrice($shop, $newest, $today, $now)) {
            return null;
        }

        usort($days, static fn (VerdictReading $a, VerdictReading $b): int => $a->firstAt <=> $b->firstAt);
        $sizeRule = self::sizeRule($shop, $days);

        $evidence = array_values(array_filter($days, static fn (VerdictReading $day): bool => $day->seller === $newest->seller && $sizeRule($day)));

        // The newest reading must itself pass every rule, so it ends the evidence.
        if (! $sizeRule($newest) || array_last($evidence)?->lastAt !== $newest->lastAt || ! self::isCovered($evidence)) {
            return null;
        }

        $daily = self::dailyLows($evidence);

        if (count($daily) < self::MIN_DAYS) {
            return null;
        }

        $low = self::percentile($daily, 0.25);
        $median = self::percentile($daily, 0.5);
        $high = self::percentile($daily, 0.75);

        $verdict = match (true) {
            bccomp($today, $low, 4) <= 0 && bccomp(bcmul($today, '100', 4), bcmul($median, self::GOOD_FACTOR, 4), 4) <= 0 => PriceVerdict::Good,
            bccomp($today, $high, 4) === 1 && bccomp(bcmul($today, '100', 4), bcmul($median, self::HIGH_FACTOR, 4), 4) >= 0 => PriceVerdict::High,
            default => PriceVerdict::Normal,
        };

        // A shop with 40 days of history must not say "90 days". Below the
        // median too, or a price that never moves is always at its lowest.
        $coversWindow = $evidence[0]->firstAt <= $windowStart->getTimestamp() + self::COVERAGE_SLACK_SECONDS;
        $atLowest = $coversWindow && bccomp($today, $daily[0], 4) <= 0 && bccomp($today, $median, 4) === -1;

        return new ShopPriceVerdict(
            verdict: $verdict,
            low: $low,
            median: $median,
            high: $high,
            lowestIn90Days: $atLowest,
            currency: (string) $shop->currency,
        );
    }

    /**
     * Whether the newest reading of any status still stands behind the
     * stored price: a fresh evidence reading at that price, read after every
     * promotion boundary that has passed. Between a boundary and the next
     * check the stored price belongs to the state before it.
     *
     * @param  numeric-string  $today
     */
    private static function backsTodaysPrice(Shop $shop, VerdictReading $newest, string $today, CarbonImmutable $now): bool
    {
        if (! $newest->isEvidence
            || $newest->price === null
            || $newest->lastAt < $now->getTimestamp() - self::FRESH_SECONDS
            || bccomp($newest->price, $today, 2) !== 0) {
            return false;
        }

        foreach ([$shop->promotion_starts_at, $shop->promotion_ends_at] as $boundary) {
            if ($boundary !== null && $boundary->lessThanOrEqualTo($now) && $newest->lastAt <= $boundary->getTimestamp()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which readings were taken at today's pack size. A stored size must
     * match. A legacy day from before the cutoff counts while the size has not
     * changed since the cutoff; with no day after the cutoff yet, nothing
     * shows a change, so it counts. The cutoff is a midnight in Amsterdam, so
     * a day is wholly before or after it.
     *
     * @param  list<VerdictReading>  $days  Oldest first.
     * @return Closure(VerdictReading): bool
     */
    private static function sizeRule(Shop $shop, array $days): Closure
    {
        $cutoff = CarbonImmutable::parse(self::SIZE_CUTOFF, self::DAY_TIMEZONE)->getTimestamp();
        $todaysSize = VerdictReading::sizeKey($shop->pack_quantity, $shop->pack_unit);
        $firstAfterCutoff = array_find($days, static fn (VerdictReading $day): bool => $day->firstAt >= $cutoff);
        $legacyCounts = $firstAfterCutoff === null || $firstAfterCutoff->size === $todaysSize;

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
