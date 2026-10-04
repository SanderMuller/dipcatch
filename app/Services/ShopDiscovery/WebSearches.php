<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\ApiService;
use App\Models\ApiUsageDay;
use App\Models\WebSearch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * One stored search per query, shared by every product that asks it, and
 * repeated only once it is older than `search_max_age_days`.
 */
final readonly class WebSearches
{
    private const int LOCK_SECONDS = 60;

    /** Short enough that a waiter's own search and first check still fit the job's timeout. */
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(private WebSearchProvider $provider) {}

    public function enabled(): bool
    {
        return Config::boolean('dipcatch.web_discovery.enabled') && $this->provider->configured();
    }

    /**
     * The stored search for a query, searched now when there is none or it
     * is stale. Null when nothing could be searched: switched off, the daily
     * limit spent, the provider failed, or another worker held the lock too
     * long.
     */
    public function forQuery(string $query): ?WebSearch
    {
        return $this->lookUp($query)->search;
    }

    /** As {@see forQuery()}, with the reason when there is no search. */
    public function lookUp(string $query): WebSearchOutcome
    {
        if (! $this->enabled()) {
            return WebSearchOutcome::failed();
        }

        $hash = WebSearch::hashOf($query);
        $fresh = $this->fresh($hash);

        if ($fresh instanceof WebSearch) {
            return WebSearchOutcome::found($fresh);
        }

        try {
            // Under a lock on the query, and checked again inside it: two
            // products with the same title that miss together spend one search.
            $outcome = Cache::lock("web-discovery:search:{$hash}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): WebSearchOutcome => ($fresh = $this->fresh($hash)) instanceof WebSearch ? WebSearchOutcome::found($fresh) : $this->search($query, $hash));
        } catch (LockTimeoutException) {
            Log::info('Web shop discovery skipped a search: another worker held it too long; the next run tries again.', ['query_hash' => $hash]);

            return WebSearchOutcome::lockBusy();
        }

        return $outcome instanceof WebSearchOutcome ? $outcome : WebSearchOutcome::failed();
    }

    public static function isFresh(WebSearch $search): bool
    {
        return $search->searched_at->greaterThan(now()->subDays(Config::integer('dipcatch.web_discovery.search_max_age_days')));
    }

    /** The stored search for a query hash, when it is fresh. */
    public function fresh(string $hash): ?WebSearch
    {
        $search = WebSearch::query()->where('query_hash', $hash)->first();

        return $search instanceof WebSearch && self::isFresh($search) ? $search : null;
    }

    private function search(string $query, string $hash): WebSearchOutcome
    {
        if (! self::reserveSearch()) {
            Log::info('Web shop discovery skipped a search: the daily limit is spent.');
            ApiUsageDay::refused(ApiService::Serper, SerperProvider::PURPOSE);

            return WebSearchOutcome::quotaSpent();
        }

        try {
            $results = $this->provider->search($query);
        } catch (WebSearchFailed $e) {
            // A refused key or spent credit does not pass by itself.
            $level = in_array($e->getCode(), [401, 402, 403], strict: true) ? 'error' : 'warning';
            Log::log($level, 'Web shop discovery search failed; the next run tries again.', ['error' => $e->getMessage(), 'status' => $e->getCode(), 'exception' => $e]);

            return WebSearchOutcome::failed();
        }

        return WebSearchOutcome::found(WebSearch::query()->updateOrCreate(
            ['query_hash' => $hash],
            ['query' => mb_substr(WebSearch::normalise($query), 0, 255), 'results' => $results, 'searched_at' => now()],
        ));
    }

    /**
     * Counts first and compares after, on one atomic increment, so two
     * workers can never both take the last search of the day.
     * `RateLimiter::attempt()` checks before it counts, which lets them.
     */
    private static function reserveSearch(): bool
    {
        $limit = Config::integer('dipcatch.web_discovery.daily_search_limit');

        if ($limit <= 0) {
            return true;
        }

        $key = 'web-discovery:searches:' . now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        return (int) Cache::increment($key) <= $limit;
    }
}
