<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Jobs\DiscoverWebShops;
use App\Jobs\FindKlarnaPage;
use App\Jobs\LookUpKlarnaLead;
use App\Jobs\ReadKlarnaLeads;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\ShopFetcher;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Klarna pages as a source of shop leads for web discovery, see
 * specs/klarna-shop-leads.md §5.
 *
 * Three steps, each its own short job: find the product's Klarna page, read
 * the shops it lists, and look each one up on its own site. The lookups land
 * as web findings and go through the normal checks from there. Every write,
 * lead findings included, runs under a lock on the product's discovery row
 * and only while it holds the generation the step was dispatched for, so a
 * step of a source that was replaced writes nothing.
 */
final readonly class KlarnaDiscovery
{
    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public function __construct(
        private WebSearches $searches,
        private KlarnaPageSearch $pageSearch,
        private ShopFetcher $fetcher,
        private KlarnaLeadPicker $picker,
    ) {}

    /** Dispatches the step this generation still lacks. */
    public function continue(Product $product): void
    {
        $discovery = WebDiscovery::query()->find($product->id);

        if (! KlarnaSource::enabled() || ! $discovery instanceof WebDiscovery || $discovery->klarna_checked_at !== null) {
            return;
        }

        $generation = $discovery->klarna_generation;

        if ($discovery->klarna_url === null) {
            dispatch(new FindKlarnaPage((string) $product->id, $generation));

            return;
        }

        if ($discovery->klarna_leads === null) {
            dispatch(new ReadKlarnaLeads((string) $product->id, $generation));

            return;
        }

        $pending = array_filter($discovery->klarna_leads, static fn (array $lead): bool => $lead['state'] === self::PENDING);

        // Every lead settled, but the step that finishes the work never ran.
        if ($pending === []) {
            $this->finish($product, $generation);
        }

        foreach ($pending as $entry) {
            dispatch(new LookUpKlarnaLead((string) $product->id, $generation, $entry['host']));
        }
    }

    /**
     * Step 1: the product's Klarna page. A Klarna page the product has as a
     * shop (in practice a reference link), then a Klarna result in the open
     * search, then a search for one. Only a search that ran and a check that
     * answered every result can end with "none", which also drops the lead
     * findings of an earlier page.
     */
    public function findPage(Product $product, int $generation): void
    {
        $discovery = self::current($product, $generation);

        if (! $discovery instanceof WebDiscovery || $discovery->klarna_url !== null) {
            return;
        }

        $known = $product->shops->first(static fn (Shop $shop): bool => KlarnaLeads::isKlarnaPage($shop->url))->url
            ?? KlarnaPageSearch::klarnaResults($discovery->search)[0]['link'] ?? null;

        if (is_string($known)) {
            $this->useSource($product, $generation, $known, searchId: null);

            return;
        }

        $found = $this->pageSearch->for($product);

        if (is_array($found)) {
            $this->useSource($product, $generation, $found['url'], $found['searchId']);

            return;
        }

        match ($found) {
            KlarnaPageSearch::NONE => $this->finish($product, $generation, dropLeads: true),
            KlarnaPageSearch::FAILED => $this->countAttempt($product, $generation),
            default => null,
        };
    }

    /**
     * Step 2: read the shops the page lists, keep the ones worth a lookup,
     * and store them before any lookup is dispatched.
     */
    public function readLeads(Product $product, int $generation): void
    {
        $discovery = self::current($product, $generation);

        if (! $discovery instanceof WebDiscovery || $discovery->klarna_url === null || $discovery->klarna_leads !== null) {
            return;
        }

        try {
            $fetch = $this->fetcher->fetch($discovery->klarna_url);
        } catch (FetchException|InvalidArgumentException) {
            $this->countAttempt($product, $generation);

            return;
        }

        $entries = array_map(static fn (ShopLead $lead): array => [
            'host' => $lead->host,
            'title' => $lead->title,
            'pack_quantity' => $lead->packSize?->quantity,
            'pack_unit' => $lead->packSize?->unit,
            'state' => self::PENDING,
            'attempts' => 0,
        ], $this->picker->pick($product, KlarnaLeads::fromHtml($fetch->html, $discovery->klarna_url)));

        $written = WebDiscovery::query()
            ->whereKey($product->id)
            ->where('klarna_generation', $generation)
            ->whereNull('klarna_leads')
            ->update(['klarna_leads' => json_encode($entries), 'klarna_attempts' => 0]);

        if ($written === 0) {
            return;
        }

        if ($entries === []) {
            $this->finish($product, $generation);

            return;
        }

        foreach ($entries as $entry) {
            dispatch(new LookUpKlarnaLead((string) $product->id, $generation, $entry['host']))->afterCommit();
        }
    }

    /**
     * Step 3: one shop's own page for the product, through a search on its
     * site. Its first few results become findings; the first check keeps
     * the best one per shop.
     */
    public function lookUp(Product $product, int $generation, string $host): void
    {
        $discovery = self::current($product, $generation);
        $entry = $discovery instanceof WebDiscovery ? self::entry($discovery, $host) : null;

        if (! $discovery instanceof WebDiscovery || $entry === null || $entry['state'] !== self::PENDING || $discovery->klarna_url === null) {
            return;
        }

        $outcome = $this->searches->lookUp("site:{$host} {$entry['title']}", ShoppersCountry::forProduct($product));

        if ($outcome->isDeferred()) {
            return;
        }

        if (! $outcome->search instanceof WebSearch) {
            $this->settle($product, $generation, $host, self::attempted(...));

            return;
        }

        $search = $outcome->search;
        [$added, $finished] = self::locked($product, $generation, static function (WebDiscovery $current) use ($product, $search, $host): array {
            $lead = self::entry($current, $host);

            if ($lead === null || $lead['state'] !== self::PENDING || $current->klarna_url === null) {
                return [false, false];
            }

            $added = LeadFindings::store($product, $search, $host, $current->klarna_url, $lead);

            return [$added, self::saveLead($current, $host, array_replace($lead, ['state' => self::DONE]))];
        }) ?? [false, false];

        if ($finished) {
            $this->finish($product, $generation);
        }

        if ($added) {
            dispatch(new DiscoverWebShops((string) $product->id));
        }
    }

    /** One attempt of a step: a crash, a failed fetch, a failed search or check. */
    public function countAttempt(Product $product, int $generation): void
    {
        WebDiscovery::query()
            ->whereKey($product->id)
            ->where('klarna_generation', $generation)
            ->increment('klarna_attempts');

        $discovery = self::current($product, $generation);

        if ($discovery instanceof WebDiscovery && $discovery->klarna_attempts >= Config::integer('dipcatch.web_discovery.klarna_attempts')) {
            $this->finish($product, $generation);
        }
    }

    /** A lookup that crashed counts against its own lead. */
    public function countLookUpAttempt(Product $product, int $generation, string $host): void
    {
        $this->settle($product, $generation, $host, self::attempted(...));
    }

    /**
     * Changes one lead under the lock, so two lookups of one product never
     * lose each other's update.
     *
     * @param  Closure(array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}): array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}  $change
     */
    private function settle(Product $product, int $generation, string $host, Closure $change): void
    {
        $finished = self::locked($product, $generation, static function (WebDiscovery $current) use ($host, $change): bool {
            $lead = self::entry($current, $host);

            return $lead !== null && self::saveLead($current, $host, $change($lead));
        });

        if ($finished === true) {
            $this->finish($product, $generation);
        }
    }

    /**
     * Stores one lead on the locked row. True when no lead is pending any
     * more: the Klarna work of the generation is finished.
     *
     * @param  array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}  $lead
     */
    private static function saveLead(WebDiscovery $current, string $host, array $lead): bool
    {
        $leads = array_map(static fn (array $entry): array => $entry['host'] === $host ? $lead : $entry, $current->klarna_leads ?? []);
        $current->forceFill(['klarna_leads' => $leads])->save();

        return ! array_any($leads, static fn (array $entry): bool => $entry['state'] === self::PENDING);
    }

    /**
     * One more attempt on a lead; at the cap it fails.
     *
     * @param  array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}  $lead
     * @return array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}
     */
    private static function attempted(array $lead): array
    {
        $attempts = $lead['attempts'] + 1;

        return array_replace($lead, [
            'attempts' => $attempts,
            'state' => $attempts >= Config::integer('dipcatch.web_discovery.klarna_attempts') ? self::FAILED : $lead['state'],
        ]);
    }

    private function useSource(Product $product, int $generation, string $url, ?int $searchId): void
    {
        $written = self::locked($product, $generation, static function (WebDiscovery $current) use ($product, $url, $searchId): bool {
            if ($current->klarna_url !== null) {
                return false;
            }

            $current->forceFill(['klarna_url' => $url, 'klarna_search_id' => $searchId, 'klarna_attempts' => 0])->save();
            KlarnaSource::dropLeadsFromOtherPages($product, $url);

            return true;
        });

        if ($written === true) {
            dispatch(new ReadKlarnaLeads((string) $product->id, $generation));
        }
    }

    /**
     * Ends the Klarna work of a generation. `$dropLeads` for a product found
     * not to be on Klarna: lead findings of an earlier page go. A step that
     * gave up keeps them.
     */
    private function finish(Product $product, int $generation, bool $dropLeads = false): void
    {
        self::locked($product, $generation, static function (WebDiscovery $current) use ($product, $dropLeads): bool {
            if ($current->klarna_checked_at === null) {
                $current->forceFill(['klarna_checked_at' => now()])->save();
            }

            if ($dropLeads) {
                KlarnaSource::dropLeadsFromOtherPages($product, source: null);
            }

            return true;
        });

        WebDiscovery::finishIfDone($product);
    }

    /**
     * Runs `$write` on the product's discovery row, locked, while it holds
     * `$generation`. Null when it holds another one.
     *
     * @template TResult
     *
     * @param  Closure(WebDiscovery): TResult  $write
     * @return TResult|null
     */
    private static function locked(Product $product, int $generation, Closure $write): mixed
    {
        return DB::transaction(static function () use ($product, $generation, $write): mixed {
            $current = WebDiscovery::query()->lockForUpdate()->find($product->id);

            return $current instanceof WebDiscovery && $current->klarna_generation === $generation ? $write($current) : null;
        });
    }

    /** Null too when the Klarna steps were switched off after this one was queued. */
    private static function current(Product $product, int $generation): ?WebDiscovery
    {
        if (! KlarnaSource::enabled()) {
            return null;
        }

        $discovery = WebDiscovery::query()->with('search')->find($product->id);

        return $discovery instanceof WebDiscovery && $discovery->klarna_generation === $generation ? $discovery : null;
    }

    /**
     * @return array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}|null
     */
    private static function entry(WebDiscovery $discovery, string $host): ?array
    {
        return array_find($discovery->klarna_leads ?? [], static fn (array $lead): bool => $lead['host'] === $host);
    }
}
