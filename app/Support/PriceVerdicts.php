<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PriceVerdict;
use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Says whether a shop's price today is good, normal or high against that
 * shop's own last 90 days. It describes the past and never forecasts. See
 * specs/buy-now-or-wait.md, section 2.
 *
 * A shop with too little evidence, or whose stored price no fresh reading
 * backs, gets nothing at all.
 *
 * Each shop's history is cached for an hour, and cleared when a check or a
 * change to the shop could move it. The rules that depend on the time, the
 * freshness and the promotion boundaries, run on every read.
 *
 * @phpstan-import-type History from VerdictHistory
 */
final class PriceVerdicts
{
    /** Versioned: an entry of an older shape must not be read after a deploy. */
    private const string CACHE_PREFIX = 'price-verdict:v1:';

    private const int CACHE_SECONDS = 3600;

    /** Far longer than an entry lives, so an expired generation matches no entry. */
    private const int GENERATION_DAYS = 30;

    private const int WINDOW_DAYS = 90;

    private const int FRESH_SECONDS = 3 * 86_400;

    /** How late the first reading may come for "Lowest in 90 days" to cover the window. */
    private const int COVERAGE_SLACK_SECONDS = 3 * 86_400;

    private const string GOOD_PERCENT_OF_MEDIAN = '95';

    private const string HIGH_PERCENT_OF_MEDIAN = '105';

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

    /** Uncached: an alert reads the check it is written for, inside that check's transaction. */
    public static function isLowestIn90Days(Shop $shop): bool
    {
        return (self::judge(new Collection([$shop]), cached: false)[$shop->id] ?? null)?->lowestIn90Days === true;
    }

    /**
     * One verdict per shop that has one. The shops not in the cache cost two
     * queries together, whatever their number: see {@see VerdictReading::read()}.
     *
     * @param  Collection<int, Shop>  $shops
     * @return array<string, ShopPriceVerdict>
     */
    public static function forShops(Collection $shops): array
    {
        return self::judge($shops, cached: true);
    }

    /**
     * After the commit, so the next read sees the change. A new generation
     * for the shop, not a delete: a reader that computed from the state
     * before the commit stores its entry under the old generation, and
     * nobody reads that entry again. A random value, not a counter, so no
     * store can reset it to one already used. Rolled back, the change moved
     * nothing.
     */
    public static function forget(string $shopId): void
    {
        DB::afterCommit(static fn (): bool => Cache::put(self::generationKey($shopId), Str::random(), now()->addDays(self::GENERATION_DAYS)));
    }

    private static function entryKey(string $shopId): string
    {
        return self::CACHE_PREFIX . $shopId;
    }

    private static function generationKey(string $shopId): string
    {
        return self::CACHE_PREFIX . 'generation:' . $shopId;
    }

    /**
     * @param  Collection<int, Shop>  $shops
     * @return array<string, ShopPriceVerdict>
     */
    private static function judge(Collection $shops, bool $cached): array
    {
        $candidates = $shops->filter(static fn (Shop $shop): bool => ! $shop->isReference() && is_numeric($shop->current_price));

        if ($candidates->isEmpty()) {
            return [];
        }

        $now = CarbonImmutable::now();
        $windowStart = $now->subDays(self::WINDOW_DAYS);
        $histories = self::histories($candidates, $windowStart, $cached);
        $results = [];

        foreach ($candidates as $shop) {
            $verdict = self::verdict($shop, $histories[$shop->id], $windowStart, $now);

            if ($verdict instanceof ShopPriceVerdict) {
                $results[$shop->id] = $verdict;
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, Shop>  $shops
     * @return array<string, History>
     */
    private static function histories(Collection $shops, CarbonImmutable $windowStart, bool $cached): array
    {
        $found = $cached ? Cache::many($shops->flatMap(static fn (Shop $shop): array => [self::entryKey($shop->id), self::generationKey($shop->id)])->all()) : [];
        $histories = [];
        $stamps = [];
        $missing = [];

        foreach ($shops as $shop) {
            $generation = $found[self::generationKey($shop->id)] ?? null;
            // With the pack size the history was read for: a reader holding
            // the shop from before a size change must not store the old
            // size's history for the new one.
            $stamps[$shop->id] = (is_string($generation) ? $generation : '') . '|' . VerdictReading::sizeKey($shop->pack_quantity, $shop->pack_unit);
            $entry = $found[self::entryKey($shop->id)] ?? null;

            if (is_array($entry) && ($entry['stamp'] ?? null) === $stamps[$shop->id] && is_array($entry['history'] ?? null)) {
                /** @var History $history */
                $history = $entry['history'];
                $histories[$shop->id] = $history;
            } else {
                $missing[] = $shop;
            }
        }

        if ($missing === []) {
            return $histories;
        }

        $computed = [];

        foreach (VerdictHistory::of($missing, $windowStart) as $shopId => $history) {
            $histories[$shopId] = $history;
            $computed[self::entryKey($shopId)] = ['stamp' => $stamps[$shopId] ?? '', 'history' => $history];
        }

        if ($cached) {
            Cache::putMany($computed, self::CACHE_SECONDS);
        }

        return $histories;
    }

    /** @param  History  $history */
    private static function verdict(Shop $shop, array $history, CarbonImmutable $windowStart, CarbonImmutable $now): ?ShopPriceVerdict
    {
        /** @var numeric-string $today */
        $today = (string) $shop->current_price;
        ['newest' => $newest, 'stats' => $stats] = $history;

        if ($newest === null || $stats === null || ! self::backsTodaysPrice($shop, $newest, $today, $now)) {
            return null;
        }

        ['low' => $low, 'median' => $median, 'high' => $high] = $stats;

        $verdict = match (true) {
            bccomp($today, $low, 4) <= 0 && bccomp(bcmul($today, '100', 4), bcmul($median, self::GOOD_PERCENT_OF_MEDIAN, 4), 4) <= 0 => PriceVerdict::Good,
            bccomp($today, $high, 4) === 1 && bccomp(bcmul($today, '100', 4), bcmul($median, self::HIGH_PERCENT_OF_MEDIAN, 4), 4) >= 0 => PriceVerdict::High,
            default => PriceVerdict::Normal,
        };

        // A shop with 40 days of history must not say "90 days". Below the
        // median too, or a price that never moves is always at its lowest.
        $coversWindow = $stats['firstAt'] <= $windowStart->getTimestamp() + self::COVERAGE_SLACK_SECONDS;
        $atLowest = $coversWindow && bccomp($today, $stats['lowest'], 4) <= 0 && bccomp($today, $median, 4) === -1;

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
     * @param  array{lastAt: int, price: numeric-string|null, isEvidence: bool}  $newest
     * @param  numeric-string  $today
     */
    private static function backsTodaysPrice(Shop $shop, array $newest, string $today, CarbonImmutable $now): bool
    {
        if (! $newest['isEvidence']
            || $newest['price'] === null
            || $newest['lastAt'] < $now->getTimestamp() - self::FRESH_SECONDS
            || bccomp($newest['price'], $today, 2) !== 0) {
            return false;
        }

        return array_all(
            [$shop->promotion_starts_at, $shop->promotion_ends_at],
            static fn (?CarbonInterface $boundary): bool => $boundary === null
                || $boundary->greaterThan($now)
                || $newest['lastAt'] > $boundary->getTimestamp(),
        );
    }
}
