<?php declare(strict_types=1);

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * A random salt per day, kept only in the cache and expired shortly after
 * its day ends. Once it is gone, a visitor hash from that day cannot be
 * recomputed from an IP address, so visitors cannot be followed across days.
 */
final class DailySalt
{
    /** Covers a request that starts just before midnight. */
    public const int GRACE_MINUTES = 10;

    public static function for(CarbonImmutable $day): string
    {
        $store = config('analytics.salt_store');
        $cache = Cache::store(is_string($store) ? $store : null);
        $key = self::key($day);
        $salt = $cache->get($key);

        if (! is_string($salt)) {
            self::forgetOlderSalts($cache, $day);

            // `add` writes only when the key is missing, so concurrent first
            // requests of the day all end up with the same salt.
            $cache->add($key, bin2hex(random_bytes(32)), $day->endOfDay()->addMinutes(self::GRACE_MINUTES));
            $salt = $cache->get($key);
        }

        return is_string($salt) ? $salt : '';
    }

    /**
     * A store such as the database cache removes an expired key only when it
     * is read again. A salt older than the longest retention has no stored
     * hash left to match, so older keys are not looked for.
     */
    private static function forgetOlderSalts(Repository $cache, CarbonImmutable $day): void
    {
        $days = max(
            config()->integer('analytics.retention.anonymous_days', 30),
            config()->integer('analytics.retention.linked_days', 90),
        ) + 1;

        foreach (range(2, $days) as $daysBack) {
            $cache->forget(self::key($day->subDays($daysBack)));
        }
    }

    private static function key(CarbonImmutable $day): string
    {
        return 'analytics:salt:' . $day->toDateString();
    }

    public static function visitorHash(CarbonImmutable $day, string $ip, string $userAgent, string $site): string
    {
        return substr(hash('sha256', self::for($day) . '|' . $ip . '|' . $userAgent . '|' . $site), 0, 32);
    }
}
