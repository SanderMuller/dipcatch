<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The last reading of a shop page, shared by every row that tracks it.
 *
 * Each row was checked on its own schedule, so five people tracking one page
 * cost five fetches per interval, each against the same shop's rate budget.
 * Their due times are unrelated — they depend on when each row was added — so
 * a short cache catches almost none of them: two rows land within 30 minutes
 * of each other about 2% of the time on a 24-hour interval.
 *
 * So a reading is kept for the longest interval any plan has, and a row takes
 * it when it is younger than that row's own interval. The row is then dated at
 * the reading, not at the reuse, and comes due again when the reading is one
 * interval old — its data is never older than its plan promises. Rows on one
 * page drift onto one schedule, and the page is fetched about once per the
 * shortest interval among the plans tracking it.
 *
 * Only rows that would read the page the same way share: the same normalized
 * URL, the same variant, the same fallback currency, and no CSS selectors of
 * their own.
 */
final readonly class PageReadings
{
    /** Bump when the stored shape changes, so a deploy never reads an old one. */
    private const string PREFIX = 'page-reading:v1:';

    /**
     * A reading this row may use instead of fetching, or null. `$mustFetch`
     * is a person asking for a check, or the second reading a large drop
     * needs: those always fetch.
     */
    public function recent(Shop $shop, bool $mustFetch = false): ?PageReading
    {
        $key = self::key($shop);

        if ($key === null || $mustFetch) {
            return null;
        }

        try {
            $stored = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        $reading = PageReading::fromStored($stored);
        $maxAgeHours = $shop->product?->user?->entitlements()->recheckIntervalHours()
            ?? Entitlements::of(Plan::Free)->recheckIntervalHours();

        return $reading !== null && $reading->readAt->greaterThan(now()->subHours($maxAgeHours))
            ? $reading
            : null;
    }

    /** The outcome of a shared reading this row may take, or null to fetch. */
    public function sharedOutcome(Shop $shop, bool $mustFetch = false): ?CheckOutcome
    {
        $reading = $this->recent($shop, $mustFetch);

        return $reading === null ? null : CheckOutcome::shared($reading);
    }

    /** Keep a fresh successful reading for the rows that share the page, and pass the outcome on. */
    public function remember(Shop $shop, CheckOutcome $outcome): CheckOutcome
    {
        $key = self::key($shop);

        if ($key === null || $outcome->snapshot === null) {
            return $outcome;
        }

        $reading = new PageReading($outcome->snapshot, $outcome->adapterKey, $outcome->imageUrl, CarbonImmutable::now());

        try {
            Cache::put($key, $reading->toStored(), now()->addHours(self::keepHours()));
        } catch (Throwable) {
            // A reading not kept costs the next row one fetch, nothing more.
        }

        return $outcome;
    }

    /** Null when this row reads the page its own way and must not share. */
    public static function key(Shop $shop): ?string
    {
        if ($shop->price_selector !== null || $shop->title_selector !== null || $shop->image_selector !== null) {
            return null;
        }

        return self::PREFIX . hash('sha256', implode("\n", [
            (string) $shop->url_hash,
            (string) $shop->variant_key,
            strtoupper((string) $shop->currency),
        ]));
    }

    /** As long as the longest plan interval: no row can use an older reading. */
    private static function keepHours(): int
    {
        return max(
            Entitlements::of(Plan::Free)->recheckIntervalHours(),
            Entitlements::of(Plan::Pro)->recheckIntervalHours(),
        );
    }
}
