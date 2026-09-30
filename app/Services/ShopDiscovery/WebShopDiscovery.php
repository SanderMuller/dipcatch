<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\CheckWebFindings;
use App\Jobs\DiscoverWebShops;
use App\Jobs\ReadWebFinding;
use App\Models\Product;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Config;

/**
 * Web shop discovery, see specs/web-shop-discovery.md §5.2. This class runs
 * the search and the first check; `WebPageReads` and `WebSecondCheck` run the
 * rest. Each step does only what the findings' state still lacks, so a job
 * that runs twice does no harm.
 */
final readonly class WebShopDiscovery
{
    public function __construct(
        private WebSearches $searches,
        private ShopMatchCheck $shopMatch,
    ) {}

    private function runsFor(Product $product): bool
    {
        $product->loadMissing(['user', 'shops']);

        return $this->searches->enabled()
            && TypeSafeClient::configured()
            && $product->user instanceof User
            && $product->user->wantsShopChecks()
            && strcasecmp($product->currency, 'EUR') === 0
            && $product->shops->isNotEmpty();
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

    public function discover(Product $product): void
    {
        if (! $this->runsFor($product)) {
            WebDiscovery::mark($product, WebDiscoveryState::Done);

            return;
        }

        WebDiscovery::mark($product, WebDiscoveryState::Running);
        $search = $this->searches->forQuery($product->title);

        // Without a search (the day's searches spent, or the provider down)
        // the findings stored before still go on.
        if ($search instanceof WebSearch) {
            $kept = WebResultFilter::keep($product, $search);
            $this->startOver($product, $search, $kept);
            $this->storeNew($product, $search, $kept);
        }

        $this->firstCheck($product);
        $this->queueDueReadsOrCheck($product);
        WebDiscovery::finishIfDone($product);
    }

    /**
     * Findings start over when the search they came from was refreshed (by
     * this or another product with the same title), when the product's title
     * or pack sizes changed, or when a barcode they were checked against is
     * gone. One no longer kept from the search is deleted; hidden ones stay hidden.
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

        foreach (WebShopFinding::query()->where('product_id', $product->id)->whereNull('dismissed_at')->get() as $finding) {
            if (! $searchChanged && $finding->fingerprint === $fingerprint && ! $finding->hasStaleGtins($gtins)) {
                continue;
            }

            $result = $results[$finding->url_hash] ?? null;

            if ($result === null) {
                // A Hide can land after the select; a hidden row stays.
                WebShopFinding::query()->whereKey($finding->id)->whereNull('dismissed_at')->delete();

                continue;
            }

            WebShopFinding::query()->whereKey($finding->id)->update([
                'web_search_id' => $search->id,
                'search_title' => mb_substr($result['title'], 0, 255),
                'snippet' => $result['snippet'],
                'status' => WebFindingStatus::New,
                'fingerprint' => $fingerprint,
                'generation' => $finding->generation + 1,
                'first_chance' => null,
                'add_url' => null,
                'served_host' => null,
                'page_title' => null,
                'page_pack_quantity' => null,
                'page_pack_unit' => null,
                'page_price' => null,
                'page_currency' => null,
                'page_gtin' => null,
                'matched_gtin' => null,
                'checked_gtins' => null,
                'read_at' => null,
                'second_chance' => null,
                'failure' => null,
                'attempts' => 0,
                'next_attempt_at' => null,
                'checked_at' => null,
            ]);
        }

        WebDiscovery::query()->whereKey($product->id)->update(['web_search_id' => $search->id, 'search_searched_at' => $search->searched_at]);
    }

    /**
     * @param  list<array{url: string, url_hash: string, host: string, title: string, snippet: string}>  $kept
     */
    private function storeNew(Product $product, WebSearch $search, array $kept): void
    {
        $fingerprint = WebShopFinding::fingerprintFor($product);

        WebShopFinding::query()->insertOrIgnore(array_map(static fn (array $result): array => [
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

        $answers = $this->shopMatch->ask($product, ShopCheckPurpose::WebDiscovery, $candidates, quick: false);
        arsort($answers);

        $readFrom = Config::float('dipcatch.web_discovery.read_from');
        $slots = Config::integer('dipcatch.web_discovery.max_reads_per_product') - self::readsGranted($product, $fingerprint, $readFrom);
        $gtins = WebShopFinding::trackedGtins($product);

        foreach ($answers as $key => $chance) {
            $finding = $pending->get($key);

            if (! $finding instanceof WebShopFinding) {
                continue;
            }

            $passes = $chance >= $readFrom;
            $read = $passes && $slots > 0;
            $slots -= $read ? 1 : 0;

            $finding->writeIfUnchanged(WebFindingStatus::New, [
                'status' => $read ? WebFindingStatus::PendingRead : WebFindingStatus::Rejected,
                'failure' => $passes && ! $read ? 'read_cap' : null,
                'first_chance' => $chance,
                'checked_gtins' => $gtins,
            ]);
        }
    }

    /**
     * Findings of this product and fingerprint that got a read: every one
     * that passed the first check, whatever became of the read, except the
     * ones turned away by the cap itself.
     */
    private static function readsGranted(Product $product, string $fingerprint, float $readFrom): int
    {
        return WebShopFinding::query()
            ->where('product_id', $product->id)
            ->where('fingerprint', $fingerprint)
            ->where('first_chance', '>=', $readFrom)
            ->where(static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('failure')->orWhere('failure', '!=', 'read_cap'))
            ->count();
    }

    /** Queues the reads that may run now, and the second check once no read is left. */
    private function queueDueReadsOrCheck(Product $product): void
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

        if ($due->isEmpty()) {
            self::checkWhenReadsDone($product);
        }
    }

    public static function checkWhenReadsDone(Product $product): void
    {
        WebSecondCheck::releaseStaleClaims($product);
        $current = WebShopFinding::query()->where('product_id', $product->id)->current($product)->whereNull('dismissed_at');

        if (! (clone $current)->where('status', WebFindingStatus::PendingRead)->exists() && (clone $current)->where('status', WebFindingStatus::Read)->exists()) {
            dispatch(new CheckWebFindings((string) $product->id));
        }
    }
}
