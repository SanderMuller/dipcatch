<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\ProUsers;
use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Order: specs/web-shop-discovery.md §5.1. Rank first, then oldest product
 * first within a rank; never-searched products all come before ones searched
 * long ago. Only the ranks that need a search count against the day's limit.
 */
#[Signature('dipcatch:discover-web-shops {--limit=500 : Products queued per run}')]
#[Description('Queue web shop discovery: unfinished runs, stale findings, then products never searched or searched long ago.')]
final class DiscoverWebShopsCommand extends Command
{
    private const int FINISH = 0;

    private const int RECHECK = 1;

    private const int SEARCH_NEW = 2;

    private const int SEARCH_AGAIN = 3;

    /**
     * Only the Klarna steps are left: a Klarna search and a lookup per lead.
     * Last, and counted against the day's searches, so the night after a
     * deploy cannot spend them all on Klarna before new products search.
     */
    private const int KLARNA = 4;

    public function handle(WebShopDiscovery $discovery, WebSearches $searches): int
    {
        if (! $searches->enabled() || ! TypeSafeClient::configured()) {
            // Switched on but missing a key reads like a quiet night otherwise.
            if (Config::boolean('dipcatch.web_discovery.enabled')) {
                Log::warning('Web shop discovery is on but has no search or TypeSafe key; nothing queued.');
            }

            $this->info('Web shop discovery is off or missing a key; nothing queued.');

            return self::SUCCESS;
        }

        $queued = 0;
        $limitSearches = Config::integer('dipcatch.web_discovery.daily_search_limit');
        $searchesLeft = $limitSearches;
        $limit = max(1, (int) $this->option('limit'));

        foreach ($this->ranked() as [$rank, $product]) {
            if ($queued >= $limit) {
                break;
            }

            // Zero or less lifts the limit, as it does in WebSearches.
            $cost = $rank === self::KLARNA ? 1 + Config::integer('dipcatch.web_discovery.klarna_leads_per_product') : 1;

            if ($rank >= self::SEARCH_NEW && $limitSearches > 0) {
                if ($searchesLeft < $cost) {
                    continue;
                }

                $searchesLeft -= $cost;
            }

            $queued += $discovery->queue($product) ? 1 : 0;
        }

        $this->info("Queued {$queued} products.");

        return self::SUCCESS;
    }

    /**
     * Every candidate with the reason it needs a run, most urgent first.
     *
     * @return list<array{0: int, 1: Product}>
     */
    private function ranked(): array
    {
        $products = Product::query()
            ->with(['user', 'shops'])
            ->whereIn('user_id', ProUsers::ids())
            ->whereHas('user', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('shop_checks', true))
            ->whereRaw('UPPER(currency) = ?', ['EUR'])
            ->has('shops')
            ->orderBy('created_at')
            ->get();

        $discoveries = WebDiscovery::query()
            ->with('search:id,searched_at')
            ->whereIn('product_id', $products->modelKeys())
            ->get()
            ->keyBy('product_id');
        $findings = WebShopFinding::query()
            ->select(['id', 'product_id', 'status', 'fingerprint', 'checked_gtins'])
            ->whereIn('product_id', $products->modelKeys())
            ->whereNull('dismissed_at')
            ->get()
            ->groupBy('product_id');
        $ranked = [];

        foreach ($products as $product) {
            $rank = $this->rankOf($product, $discoveries->get($product->id), $findings->get($product->id) ?? new EloquentCollection());

            if ($rank !== null) {
                $ranked[] = [$rank, $product];
            }
        }

        // Stable, so the created_at order holds within a rank.
        usort($ranked, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranked;
    }

    /**
     * @param  EloquentCollection<int, WebShopFinding>  $findings
     */
    private function rankOf(Product $product, ?WebDiscovery $discovery, EloquentCollection $findings): ?int
    {
        $search = $discovery?->search;

        if (! $discovery instanceof WebDiscovery || $search === null) {
            return self::SEARCH_NEW;
        }

        if (! WebSearches::isFresh($search)) {
            return self::SEARCH_AGAIN;
        }

        if ($findings->contains(fn (WebShopFinding $finding): bool => in_array($finding->status, WebFindingStatus::unfinished(), strict: true))) {
            return self::FINISH;
        }

        if ($discovery->klarnaUnfinished()) {
            return self::KLARNA;
        }

        if ($discovery->state !== WebDiscoveryState::Done) {
            return self::FINISH;
        }

        $fingerprint = WebShopFinding::fingerprintFor($product);
        $gtins = WebShopFinding::trackedGtins($product);
        $refreshedElsewhere = $discovery->search_searched_at === null || $discovery->search_searched_at->lessThan($search->searched_at);

        if ($refreshedElsewhere || $findings->contains(fn (WebShopFinding $finding): bool => $finding->fingerprint !== $fingerprint || $finding->hasStaleGtins($gtins))) {
            return self::RECHECK;
        }

        return null;
    }
}
