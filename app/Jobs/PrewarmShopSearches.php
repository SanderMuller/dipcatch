<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\WebSearch;
use App\Services\ShopDiscovery\KlarnaPageSearch;
use App\Services\ShopDiscovery\KlarnaSource;
use App\Services\ShopDiscovery\ShoppersCountry;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the web searches of shop discovery while someone looks at the preview
 * of a pasted page, before the save. Searches are stored per query, so the
 * discovery that starts on save finds them done if the title does not
 * change. No product or finding is stored.
 */
#[Tries(1)]
#[Timeout(75)]
final class PrewarmShopSearches implements ShouldQueue
{
    use Queueable;

    /**
     * Previews per account that may search, per hour and per day. A preview
     * spends paid searches from the app-wide daily limit even when no
     * product follows.
     */
    public const int PER_HOUR = 10;

    public const int PER_DAY = 30;

    /** `nl` for a job queued before searches had a country. */
    public function __construct(public string $title, public string $country = 'nl') {}

    /** Only where discovery would search after the save, and within the account's budget. */
    public static function dispatchFor(User $user, string $title, string $currency): void
    {
        $title = trim($title);

        if ($title === '' || ! app(WebShopDiscovery::class)->runsForOwner($user, $currency)) {
            return;
        }

        if (! self::reserve("dipcatch:prewarm-searches:hour:{$user->id}:" . now()->format('Y-m-d-H'), self::PER_HOUR)
            || ! self::reserve("dipcatch:prewarm-searches:day:{$user->id}:" . now()->toDateString(), self::PER_DAY)) {
            return;
        }

        dispatch(new self($title, ShoppersCountry::of($user)));
    }

    /**
     * Counts first and compares after, on one atomic increment, so two
     * previews at once cannot both take the last place.
     */
    private static function reserve(string $key, int $limit): bool
    {
        Cache::add($key, 0, now()->addDay());

        return (int) Cache::increment($key) <= $limit;
    }

    public function handle(WebSearches $searches): void
    {
        $search = $searches->lookUp($this->title, $this->country)->search;

        // Discovery searches for the Klarna page only when the open search
        // has none among its results.
        if (KlarnaSource::enabled() && KlarnaPageSearch::searchesIn($this->country) && $search instanceof WebSearch && KlarnaPageSearch::klarnaResults($search) === []) {
            $searches->lookUp(KlarnaPageSearch::queryFor($this->title, $this->country), $this->country);
        }
    }
}
