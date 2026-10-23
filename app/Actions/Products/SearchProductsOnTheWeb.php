<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Billing\Entitlements;
use App\Models\User;
use App\Models\WebSearch;
use App\Services\ProductSearch\NameSearchGate;
use App\Services\ProductSearch\NameSearchResult;
use App\Services\ProductSearch\NameSearchResults;
use App\Services\ProductSearch\NameSearchSource;
use App\Services\ProductSearch\NameSearchVerdict;
use App\Services\ProductSearch\WebNameSearch;
use App\Services\ProductSearch\WebNameSearchStatus;
use App\Services\ShopDiscovery\KlarnaLeads;
use App\Services\ShopDiscovery\WebSearchBudget;
use App\Services\ShopDiscovery\WebSearches;
use App\Support\NotAShop;
use App\Support\UnservableShops;
use App\Support\UrlNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * One web search for a name typed in the add-product wizard, run only when
 * the user asks for it. A stored search is free and skips the gate; a new
 * one passes the user's daily cap, the gate and the app-wide cap, and the
 * user pays only when it ran and was stored.
 *
 * See `specs/product-name-search.md` Sections 4 and 4a.
 */
final readonly class SearchProductsOnTheWeb
{
    /** All three sources are Dutch, so the search is too. */
    private const string COUNTRY = 'nl';

    private const int MIN_LENGTH = 3;

    /** Covers the Jev timeout, the query lock wait and the Serper timeout. */
    private const int LOCK_SECONDS = 90;

    public function __construct(
        private WebSearches $searches,
        private NameSearchGate $gate,
    ) {}

    /**
     * @param  string  $typed  The text the local results describe.
     * @param  NameSearchVerdict|null  $knownVerdict  The gate's verdict the wizard kept for this text, so Jev is asked once.
     */
    public function __invoke(User $user, string $typed, NameSearchResults $local, ?NameSearchVerdict $knownVerdict = null): WebNameSearch
    {
        $searchText = self::searchTextOf($typed);

        if (mb_strlen($searchText) < self::MIN_LENGTH) {
            return new WebNameSearch(WebNameSearchStatus::TooShort);
        }

        $stored = $this->searches->stored($searchText, self::COUNTRY);

        if ($stored instanceof WebSearch) {
            return new WebNameSearch(WebNameSearchStatus::Found, self::shown($stored));
        }

        $lock = Cache::lock("name-search:{$user->id}:" . hash('sha256', WebSearch::normalise($searchText)), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return new WebNameSearch(WebNameSearchStatus::Busy);
        }

        try {
            return $this->searchNow($user, $searchText, $local, $knownVerdict);
        } finally {
            $lock->release();
        }
    }

    /**
     * The one string that serves as the provider query and the cache key:
     * the typed text without search operators, so a name search can never
     * write or read the key of a discovery `site:` query.
     */
    public static function searchTextOf(string $typed): string
    {
        $text = preg_replace('/(?<!\S)(?:site|inurl|intitle|filetype):\S*/iu', ' ', $typed) ?? '';
        $text = preg_replace('/(?<!\S)-\S*/u', ' ', $text) ?? '';
        $text = preg_replace('/["\x{201C}\x{201D}\x{201E}]/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Whether the text has a stored search, which is free at any cap. */
    public function isStored(string $typed): bool
    {
        return $this->searches->stored(self::searchTextOf($typed), self::COUNTRY) instanceof WebSearch;
    }

    /** Whether a new web search can run. Off, a stored search still shows. */
    public function enabled(): bool
    {
        return $this->searches->enabled();
    }

    public function capSpent(User $user): bool
    {
        $cap = Entitlements::for($user)->webSearchesPerDay();

        // The limiter reads a cap of 0 as unspent until its first hit.
        return $cap <= 0 || RateLimiter::tooManyAttempts(self::capKey($user), $cap);
    }

    private function searchNow(User $user, string $searchText, NameSearchResults $local, ?NameSearchVerdict $knownVerdict): WebNameSearch
    {
        $capKey = self::capKey($user);
        $cap = Entitlements::for($user)->webSearchesPerDay();

        // Before the gate, so a spent cap or a switched-off search never asks Jev.
        if ($this->capSpent($user)) {
            return new WebNameSearch(WebNameSearchStatus::CapSpent);
        }

        if (! $this->searches->enabled()) {
            return new WebNameSearch(WebNameSearchStatus::Failed);
        }

        $verdict = $knownVerdict ?? $this->gate->verdict($user, $searchText, $local);

        if (! $verdict->allowsSearch()) {
            return new WebNameSearch(
                $verdict === NameSearchVerdict::MatchesEveryWord ? WebNameSearchStatus::MatchesEveryWord : WebNameSearchStatus::TooVague,
                verdict: $verdict,
            );
        }

        // Counted first and compared after, so two texts searched at once
        // cannot both take the last slot.
        if (RateLimiter::hit($capKey, self::secondsLeftToday()) > $cap) {
            self::refund($capKey);

            return new WebNameSearch(WebNameSearchStatus::CapSpent, verdict: $verdict);
        }

        try {
            $outcome = $this->searches->lookUp($searchText, self::COUNTRY, WebSearchBudget::NameSearch);
        } catch (QueryException $e) {
            self::refund($capKey);
            report($e);

            return new WebNameSearch(WebNameSearchStatus::Failed, verdict: $verdict);
        }

        // A stored search a waiter got inside the lock, or no search at all.
        if (! $outcome->searchedNow) {
            self::refund($capKey);
        }

        if (! $outcome->search instanceof WebSearch) {
            return new WebNameSearch(WebNameSearchStatus::Failed, verdict: $verdict);
        }

        return new WebNameSearch(WebNameSearchStatus::Found, self::shown($outcome->search), $verdict);
    }

    /**
     * The results worth showing: no comparison sites, Klarna pages or shops
     * DipCatch cannot serve. Display only; a pick still goes through the
     * probe and its refusals.
     *
     * @return list<NameSearchResult>
     */
    private static function shown(WebSearch $search): array
    {
        $shown = [];

        foreach ($search->results as $result) {
            try {
                $host = UrlNormalizer::normalizeHost((string) parse_url(UrlNormalizer::normalize($result['link']), PHP_URL_HOST));
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($host === '' || NotAShop::covers($host) || KlarnaLeads::isKlarnaPage($result['link']) || UnservableShops::reasonFor($host) !== null) {
                continue;
            }

            $shown[] = new NameSearchResult(
                source: NameSearchSource::Web,
                title: $result['title'],
                shopLabel: $host,
                host: $host,
                url: $result['link'],
                snippet: $result['snippet'] !== '' ? $result['snippet'] : null,
            );
        }

        return $shown;
    }

    /**
     * Dated in UTC, not the account's timezone: a timezone change would give
     * the account a new key, and so a new day of searches.
     */
    private static function capKey(User $user): string
    {
        return "name-search:web:{$user->id}:" . now('UTC')->toDateString();
    }

    private static function secondsLeftToday(): int
    {
        $now = now('UTC');

        return max(1, (int) ceil($now->diffInSeconds($now->copy()->endOfDay())));
    }

    /** Never below zero: a counter that expired in between is not given a credit. */
    private static function refund(string $capKey): void
    {
        if (RateLimiter::attempts($capKey) > 0) {
            RateLimiter::decrement($capKey, self::secondsLeftToday());
        }
    }
}
