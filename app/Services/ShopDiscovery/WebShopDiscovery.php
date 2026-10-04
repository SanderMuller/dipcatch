<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\CheckWebFindings;
use App\Jobs\DiscoverWebShops;
use App\Jobs\ReadWebFinding;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Web shop discovery, see specs/web-shop-discovery.md §5.2. This class runs
 * the search and the first check; `WebPageReads` and `WebSecondCheck` run the
 * rest. Each step does only what the findings' state still lacks, so a job
 * that runs twice does no harm.
 */
final readonly class WebShopDiscovery
{
    private const int RECENTLY_ADDED_MINUTES = 10;

    /** Early second checks per product in that window, each a Jev call from the account's daily budget. */
    private const int EARLY_CHECKS = 4;

    /** Longer than one Jev call with its retry. */
    private const int FIRST_CHECK_LOCK_SECONDS = 60;

    public function __construct(
        private WebSearches $searches,
        private ShopMatchCheck $shopMatch,
        private KlarnaDiscovery $klarna,
        private BarcodeSearch $barcodeSearch,
    ) {}

    private function runsFor(Product $product): bool
    {
        $product->loadMissing(['user', 'shops']);

        return $product->user instanceof User
            && $this->runsForOwner($product->user, $product->currency)
            && $product->shops->isNotEmpty();
    }

    /** The part of the gate that needs no product yet: the account and the currency. */
    public function runsForOwner(User $user, string $currency): bool
    {
        return $this->searches->enabled()
            && TypeSafeClient::configured()
            && $user->wantsShopChecks()
            && strcasecmp($currency, 'EUR') === 0;
    }

    public function queue(Product $product): bool
    {
        if (! $this->runsFor($product)) {
            return false;
        }

        WebDiscovery::markQueued($product);
        dispatch(new DiscoverWebShops((string) $product->id));

        return true;
    }

    /**
     * A Klarna page someone pasted becomes the product's lead source, and its
     * shops are looked up now. False when the URL is not a Klarna product
     * page, web discovery does not run for the product, or the Klarna steps
     * are off. The same page pasted again while it is still being looked up
     * changes nothing.
     */
    public function useKlarnaPage(Product $product, string $url): bool
    {
        if (! KlarnaSource::enabled() || ! KlarnaLeads::isKlarnaPage($url) || ! $this->runsFor($product)) {
            return false;
        }

        return KlarnaSource::usePasted($product, $url);
    }

    /**
     * Re-checks the findings at once when a new shop or title left them
     * stale, instead of on the next nightly run. A shop in a new pack size
     * changes the fingerprint, which hides every finding until it is checked
     * against that size; waiting for the night left the list empty all day.
     * The stored title search is reused. A barcode without a fresh stored
     * search is searched, which is one paid search.
     */
    public function requeueIfStale(Product $product): bool
    {
        $product->unsetRelation('shops');
        $fingerprint = WebShopFinding::fingerprintFor($product);
        $gtins = WebShopFinding::trackedGtins($product);

        $stale = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->whereNull('dismissed_at')
            ->get()
            ->contains(static fn (WebShopFinding $finding): bool => $finding->fingerprint !== $fingerprint || $finding->hasStaleGtins($gtins));

        return $stale && $this->queue($product);
    }

    public function discover(Product $product): void
    {
        if (! $this->runsFor($product)) {
            WebDiscovery::mark($product, WebDiscoveryState::Done);

            return;
        }

        WebDiscovery::mark($product, WebDiscoveryState::Running);
        // Without a new search (the day's searches spent, or the provider
        // down) the stored one still lets stale findings start over.
        $search = $this->searches->forQuery($product->title)
            ?? WebDiscovery::query()->find($product->id)?->search;

        // The findings stored before still go on.
        if ($search instanceof WebSearch) {
            $kept = WebResultFilter::keep($product, $search);
            $this->startOver($product, $search, $kept);
            $this->storeNew($product, $search, WebResultFilter::withoutKnownHosts($product, $kept));
        }

        // After the title search, so the title search gets the day's last search.
        $this->barcodeSearch->run($product, fn (WebSearch $search, array $kept): int => $this->storeNew($product, $search, $kept));

        // First: the Klarna jobs need only the search, so they run while the first check does.
        $this->klarna->continue($product);
        $this->firstCheckAlone($product);
        $this->queueDueReadsAndCheck($product);
        WebDiscovery::finishIfDone($product);
    }

    /**
     * Findings start over when the search they came from was refreshed (by
     * this or another product with the same title), when the product's title
     * or pack sizes changed, or when a barcode they were checked against is
     * gone. One no longer kept from the search is deleted, unless a site: or
     * barcode lookup found it; hidden ones stay hidden.
     *
     * Lead findings come from their own lookup: a refreshed search leaves
     * them, and a stale fingerprint or barcode resets them with their lead
     * kept. A rebuilt search also looks for the Klarna page again, unless the
     * Klarna work of a known page, a pasted one say, is still under way.
     *
     * @param  list<array{url: string, url_hash: string, host: string, title: string, snippet: string}>  $kept
     */
    private function startOver(Product $product, WebSearch $search, array $kept): void
    {
        $discovery = WebDiscovery::query()->find($product->id);
        $searchChanged = ! $discovery instanceof WebDiscovery
            || $discovery->web_search_id !== $search->id
            || $discovery->search_searched_at === null
            || $discovery->search_searched_at->lessThan($search->searched_at);

        $fingerprint = WebShopFinding::fingerprintFor($product);
        $gtins = WebShopFinding::trackedGtins($product);
        $results = array_column($kept, null, 'url_hash');

        $findings = WebShopFinding::query()->where('product_id', $product->id)->whereNull('dismissed_at')->get();
        // A page looked up on one shop's site ({@see storeFindingsOn()}) or
        // found by the barcode ({@see BarcodeSearch}) is not in the title
        // search, and must not be dropped for that.
        $ownSearch = WebSearch::query()
            ->whereIn('id', $findings->pluck('web_search_id')->filter()->all())
            // A title of digits only reads as a barcode.
            ->whereKeyNot($search->id)
            ->get(['id', 'query'])
            ->filter(static fn (WebSearch $own): bool => WebSearch::isLookup($own->query))
            ->pluck('id')
            ->all();

        foreach ($findings as $finding) {
            $stale = $finding->fingerprint !== $fingerprint || $finding->hasStaleGtins($gtins);

            if ($finding->isLead() || in_array($finding->web_search_id, $ownSearch, strict: true)) {
                LeadFindings::startOverIf($stale, $finding, $fingerprint);

                continue;
            }

            if (! $searchChanged && ! $stale) {
                continue;
            }

            $result = $results[$finding->url_hash] ?? null;

            if ($result === null) {
                // A Hide can land after the select; a hidden row stays.
                WebShopFinding::query()->whereKey($finding->id)->whereNull('dismissed_at')->delete();

                continue;
            }

            WebShopFinding::query()->whereKey($finding->id)->update([
                ...WebShopFinding::CLEARED_CHECKS,
                'web_search_id' => $search->id,
                'search_title' => mb_substr($result['title'], 0, 255),
                'snippet' => $result['snippet'],
                'status' => WebFindingStatus::New,
                'fingerprint' => $fingerprint,
                'generation' => $finding->generation + 1,
            ]);
        }

        if ($searchChanged) {
            KlarnaSource::searchRebuilt($product, $discovery);
        }

        WebDiscovery::query()->whereKey($product->id)->update(['web_search_id' => $search->id, 'search_searched_at' => $search->searched_at]);
    }

    /**
     * Stores a search's result on one shop as a new finding, for the usual
     * first check and page read: a supermarket product whose list page is
     * gone, looked up on the shop's own site. True when one was added.
     */
    public function storeFindingsOn(Product $product, WebSearch $search, string $host): bool
    {
        return $this->storeNew($product, $search, WebResultFilter::keepOn($product, $search, $host)) > 0;
    }

    /**
     * @param  list<array{url: string, url_hash: string, host: string, title: string, snippet: string}>  $kept
     */
    private function storeNew(Product $product, WebSearch $search, array $kept): int
    {
        $fingerprint = WebShopFinding::fingerprintFor($product);

        return WebShopFinding::query()->insertOrIgnore(array_map(static fn (array $result): array => [
            'product_id' => $product->id,
            'web_search_id' => $search->id,
            'url' => $result['url'],
            'url_hash' => $result['url_hash'],
            'host' => $result['host'],
            'search_title' => mb_substr($result['title'], 0, 255),
            'snippet' => $result['snippet'],
            'status' => WebFindingStatus::New->value,
            'fingerprint' => $fingerprint,
        ], $kept));
    }

    /**
     * One first check per product at a time. A Klarna lookup can queue the
     * next run while this one still waits on Jev, and both would send the
     * same `new` findings. The second does not wait, which could outrun its
     * job's timeout: it runs again once the first is done, for what is left.
     */
    private function firstCheckAlone(Product $product): void
    {
        $ran = Cache::lock("web-discovery:first-check:{$product->id}", self::FIRST_CHECK_LOCK_SECONDS)
            ->get(function () use ($product): bool {
                $this->firstCheck($product);

                return true;
            });

        if ($ran === false) {
            dispatch(new DiscoverWebShops((string) $product->id))->delay(self::FIRST_CHECK_LOCK_SECONDS);
        }
    }

    private function firstCheck(Product $product): void
    {
        $fingerprint = WebShopFinding::fingerprintFor($product);
        $pending = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->where('fingerprint', $fingerprint)
            ->whereNull('dismissed_at')
            ->where('status', WebFindingStatus::New)
            ->get()
            ->keyBy(static fn (WebShopFinding $finding): string => "f{$finding->id}");

        if ($pending->isEmpty()) {
            return;
        }

        $candidates = $pending->map(static fn (WebShopFinding $finding): array => ShopMatchCheck::candidate(
            shop: $finding->host,
            title: $finding->search_title,
            packSize: null,
            price: null,
            snippet: $finding->snippet,
        ))->all();
        // A Klarna lead may point at the product in another size.
        $anyPack = array_values($pending->filter(static fn (WebShopFinding $finding): bool => $finding->isLead())->keys()->all());

        $answers = $this->shopMatch->ask($product, ShopCheckPurpose::WebDiscovery, $candidates, quick: false, anyPackKeys: $anyPack);
        arsort($answers);

        $readFrom = Config::float('dipcatch.web_discovery.read_from');
        // Open-search and lead findings each have their own read cap.
        $leadReads = self::grantedReads($product, $fingerprint, $readFrom)->whereNotNull('lead_url')->pluck('host');
        $slots = [
            'open' => Config::integer('dipcatch.web_discovery.max_reads_per_product') - self::grantedReads($product, $fingerprint, $readFrom)->whereNull('lead_url')->count(),
            'lead' => Config::integer('dipcatch.web_discovery.klarna_leads_per_product') - $leadReads->count(),
        ];
        $leadHostsRead = $leadReads->unique()->values()->all();
        $gtins = WebShopFinding::trackedGtins($product);

        foreach ($answers as $key => $chance) {
            $finding = $pending->get($key);

            if (! $finding instanceof WebShopFinding) {
                continue;
            }

            $lead = $finding->isLead();
            $kind = $lead ? 'lead' : 'open';
            $passes = $chance >= $readFrom;
            // A lookup sends a few results per shop; only the best is read.
            $otherPage = $lead && $passes && in_array($finding->host, $leadHostsRead, strict: true);
            $read = $passes && ! $otherPage && $slots[$kind] > 0;
            $slots[$kind] -= $read ? 1 : 0;

            if ($read && $lead) {
                $leadHostsRead[] = $finding->host;
            }

            $finding->writeIfUnchanged(WebFindingStatus::New, [
                'status' => $read ? WebFindingStatus::PendingRead : WebFindingStatus::Rejected,
                'failure' => match (true) {
                    $otherPage => 'other_page_on_host',
                    $passes && ! $read => 'read_cap',
                    default => null,
                },
                'first_chance' => $chance,
                'checked_gtins' => $gtins,
            ]);
        }
    }

    /**
     * Findings of this product and fingerprint that got a read: every one
     * that passed the first check, whatever became of the read, except the
     * ones turned away by the cap or as another page of a shop already read.
     *
     * @return EloquentQueryBuilder<WebShopFinding>
     */
    private static function grantedReads(Product $product, string $fingerprint, float $readFrom): EloquentQueryBuilder
    {
        return WebShopFinding::query()
            ->where('product_id', $product->id)
            ->where('fingerprint', $fingerprint)
            ->where('first_chance', '>=', $readFrom)
            ->where(static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('failure')->orWhereNotIn('failure', ['read_cap', 'other_page_on_host']));
    }

    private function queueDueReadsAndCheck(Product $product): void
    {
        $due = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->current($product)
            ->whereNull('dismissed_at')
            ->where('status', WebFindingStatus::PendingRead)
            ->where(static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->get(['id']);

        foreach ($due as $finding) {
            dispatch(new ReadWebFinding($finding->id));
        }

        self::checkReadPages($product);
    }

    /**
     * Queues the second check for the pages read so far. Right after a
     * product or shop is added someone waits, so the check does not wait for
     * the other reads: a read that waits to retry does not hold back the
     * pages already read. Otherwise, and after a few such early checks, one
     * check waits for every read, as in the daily run, to keep the Jev calls
     * per product low.
     */
    public static function checkReadPages(Product $product): void
    {
        WebSecondCheck::releaseStaleClaims($product);
        $current = WebShopFinding::query()->where('product_id', $product->id)->current($product)->whereNull('dismissed_at');

        if (! (clone $current)->where('status', WebFindingStatus::Read)->exists()) {
            return;
        }

        if (self::addedRecently($product) && Cache::integer(self::earlyCheckKey($product), 0) < self::EARLY_CHECKS) {
            dispatch(new CheckWebFindings((string) $product->id, early: true))->delay(CheckWebFindings::GATHER_SECONDS);
        } elseif (! (clone $current)->where('status', WebFindingStatus::PendingRead)->exists()) {
            dispatch(new CheckWebFindings((string) $product->id));
        }
    }

    /**
     * Whether an early check may run now. Counted as it starts, so reads it
     * gathered while queued count once, and counted before it compares, so
     * two checks starting together cannot both take the last place. Past the
     * cap it waits for every read, like any other check.
     */
    public static function startsEarlyCheck(Product $product): bool
    {
        $key = self::earlyCheckKey($product);
        Cache::add($key, 0, now()->addMinutes(self::RECENTLY_ADDED_MINUTES));

        if (self::addedRecently($product) && (int) Cache::increment($key) <= self::EARLY_CHECKS) {
            return true;
        }

        return ! WebShopFinding::query()->where('product_id', $product->id)->current($product)->whereNull('dismissed_at')->where('status', WebFindingStatus::PendingRead)->exists();
    }

    private static function earlyCheckKey(Product $product): string
    {
        return "web-discovery:early-check:{$product->id}";
    }

    private static function addedRecently(Product $product): bool
    {
        $since = now()->subMinutes(self::RECENTLY_ADDED_MINUTES);
        $product->loadMissing('shops');

        return $product->created_at?->greaterThan($since) === true
            || $product->shops->contains(static fn (Shop $shop): bool => $shop->created_at?->greaterThan($since) === true);
    }
}
