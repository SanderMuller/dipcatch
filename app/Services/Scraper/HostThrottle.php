<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\Config as ScraperConfig;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class HostThrottle
{
    /**
     * Number of seconds the caller should wait before issuing a request to
     * `$url`'s host. 0 means "go now".
     *
     * Applies the larger of the configured `host.min_interval_seconds` and any
     * `$crawlDelayOverride` (e.g. from robots.txt), then adds positive jitter.
     */
    public function shouldWaitSeconds(string $url, ?int $crawlDelayOverride = null): int
    {
        $host = $this->host($url);
        if ($host === null) {
            return 0;
        }

        $last = Cache::get($this->lastKey($host));
        if (! is_int($last)) {
            return 0;
        }

        $minInterval = max(
            ScraperConfig::int('scraper.host.min_interval_seconds', 8),
            $crawlDelayOverride ?? 0,
        );
        $jitter = random_int(0, max(0, ScraperConfig::int('scraper.host.jitter_seconds', 2)));

        $earliest = $last + $minInterval + $jitter;
        $wait = $earliest - Carbon::now()->getTimestamp();

        return max(0, $wait);
    }

    /**
     * Try to acquire the per-host concurrency lock. Returns null when another
     * worker already holds it (caller treats this as Throttled).
     */
    public function acquireLock(string $url): ?Lock
    {
        $host = $this->host($url);
        if ($host === null) {
            return null;
        }

        $ttl = ScraperConfig::int('scraper.host.lock_ttl_seconds', 30);
        $lock = Cache::lock($this->lockKey($host), $ttl);

        return $lock->get() ? $lock : null;
    }

    /** Cache TTL for the per-host last-request timestamp. Long enough to outlive
     * any plausible `Crawl-delay` we'd honor — 7 days is the de-facto upper bound
     * for `Crawl-delay` in the wild. The stored value is one int per host so
     * the storage cost is negligible. */
    private const int LAST_KEY_TTL_SECONDS = 7 * 24 * 60 * 60;

    /**
     * Stamp "now" as the host's last-request time. Call before issuing the
     * actual HTTP request so concurrent jobs see the slot taken even if the
     * request takes a few seconds.
     */
    public function markStarted(string $url): void
    {
        $host = $this->host($url);
        if ($host === null) {
            return;
        }

        Cache::put(
            $this->lastKey($host),
            Carbon::now()->getTimestamp(),
            self::LAST_KEY_TTL_SECONDS,
        );
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function lockKey(string $host): string
    {
        return "scrape:host:{$host}";
    }

    private function lastKey(string $host): string
    {
        return "scrape:host:last:{$host}";
    }
}
