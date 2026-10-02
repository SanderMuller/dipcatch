<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionDismissal;
use App\Models\WebShopFinding;
use App\Services\BolFeed\BolCatalogRows;
use App\Services\Checkjebon\CatalogueLinks;
use App\Services\Suggestions\QueryTokens;
use App\Services\Suggestions\RowScore;
use App\Services\Suggestions\ShopSuggestion;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Support\PackSize;
use App\Support\SupermarketChains;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Suggests other shops for a tracked product by matching its title, pack
 * size and barcodes against the local catalogue: the checkjebon dataset and
 * bol.com offers ({@see BolCatalogRows}). The price is a
 * comparison hint, and adding a suggested shop still runs the normal probe.
 * With the shop check on, Jev's stored answers filter the matches, and rows
 * without one are sent to Jev after the response.
 *
 * See `specs/shop-suggestions.md` Section 2 for the normative rules.
 */
final class SuggestShops
{
    /** Jaccard overlap a candidate must reach to be offered. */
    private const float THRESHOLD = 0.55;

    /**
     * A chain whose newest row is older than this is dropped. Matches the
     * fail threshold of `CheckjebonFreshnessCheck`: the importer keeps a
     * chain's rows when upstream serves none, so one refreshed chain must
     * not make a stale catalogue look current.
     */
    private const int MAX_AGE_HOURS = 96;

    /**
     * Suggestions for a product, memoized for the request. The product page
     * renders two instances of the suggestions component (the panel and the
     * copy inside the add-shop form), and the catalogue scan is the
     * expensive part — paying it twice per page is waste. The action is bound
     * per request, so the memo cannot outlive one.
     *
     * @var array<string, list<ShopSuggestion>>
     */
    private array $memo = [];

    /**
     * One set for every product, unlike `$memo` above, because chain
     * freshness does not depend on which product is asking.
     *
     * `dismiss()` must not clear it: a dismissal changes which rows are
     * offered, never which chains are fresh.
     *
     * The container flushes this between HTTP requests, Octane operations
     * and queued jobs, but not inside one `artisan` process — no command
     * asks this action for many products today, and one that did would hold
     * one set, and one freshness cutoff, for its whole run.
     *
     * @var array<string, CheckjebonChain>|null
     */
    private ?array $freshChains = null;

    public function __construct(private readonly ShopMatchCheck $shopMatch) {}

    /**
     * Pass `$verify` false where the suggestions are only listed, such as the
     * dashboard across many products: rows Jev has not answered stay
     * unanswered instead of spending a paid check on every visit.
     *
     * @return list<ShopSuggestion>
     */
    public function __invoke(Product $product, bool $verify = true): array
    {
        return $this->memo[$product->id] ??= $this->compute($product, $verify);
    }

    /**
     * @return list<ShopSuggestion>
     */
    private function compute(Product $product, bool $verify): array
    {
        if (strcasecmp($product->currency, 'EUR') !== 0) {
            return [];
        }

        $chains = $this->eligibleChains($product);
        $queries = $this->queriesFor($product);

        if ($chains === [] || $queries === []) {
            return [];
        }

        $verdicts = $this->shopMatch->applies($product)
            ? SuggestionVerdicts::for($product, self::THRESHOLD)
            : SuggestionVerdicts::off(self::THRESHOLD);

        $gtins = self::gtinsOf($product);
        $rows = $this->candidateRows($product, $chains, $queries, $gtins);
        $suggestions = $this->rank($this->bestPerChain($rows, $chains, $queries, $gtins, $verdicts));

        if ($verify && $verdicts->unchecked() !== []) {
            VerifyShopSuggestions::afterResponseFor($product, $verdicts->unchecked());
        }

        if ($verify) {
            CheckSuggestedLinks::afterResponseFor($product, $suggestions, CheckSuggestedLinks::lost($rows, $queries, $gtins, $suggestions, self::THRESHOLD));
        }

        return $suggestions;
    }

    /** After a shop is hidden, every product's suggestions in this request are stale. */
    public function forgetSuggestions(): void
    {
        $this->memo = [];
    }

    /**
     * Whether the catalogue can answer at all: at least one chain inside the
     * freshness window. A surface uses this to tell "nothing matched" from
     * "nothing to match against" — a stale or empty dataset is an
     * operational problem, not an answer about this product.
     */
    public function hasUsableCatalogue(): bool
    {
        return $this->freshChains() !== [];
    }

    /**
     * Record a rejected suggestion. Idempotent: a double click or two tabs
     * must not raise a unique-key error.
     */
    public function dismiss(Product $product, string $chain, string $externalId): void
    {
        unset($this->memo[$product->id]);

        DB::table(new ShopSuggestionDismissal()->getTable())->insertOrIgnore([
            'product_id' => $product->id,
            'chain' => $chain,
            'external_id' => $externalId,
            'dismissed_at' => now(),
        ]);
    }

    /**
     * Chains with rows inside the freshness window that the product does not
     * already track and its owner has not hidden, keyed by chain.
     *
     * @return array<string, CheckjebonChain>
     */
    private function eligibleChains(Product $product): array
    {
        $trackedHosts = $product->shops
            ->map(static fn (Shop $shop): string => is_string($shop->host) ? $shop->host : '')
            ->filter()
            ->values()
            ->all();

        return HiddenShop::withoutHiddenChains($product->user, array_filter(
            $this->freshChains(),
            static fn (CheckjebonChain $chain): bool => array_intersect(
                SupermarketChains::hosts($chain->chain, $chain->base_url),
                $trackedHosts,
            ) === [],
        ));
    }

    /**
     * @return array<string, CheckjebonChain>
     */
    private function freshChains(): array
    {
        return $this->freshChains ??= $this->readFreshChains();
    }

    /**
     * Chains holding rows no older than the freshness window, keyed by chain.
     * Per chain, never one global maximum: the importer keeps a chain's rows
     * when upstream serves none, so a refreshed AH would otherwise make a
     * month-old Jumbo catalogue look current.
     *
     * @return array<string, CheckjebonChain>
     */
    private function readFreshChains(): array
    {
        $freshness = CheckjebonPrice::query()
            ->selectRaw('supermarket, max(refreshed_at) as chain_refreshed_at')
            ->groupBy('supermarket')
            ->pluck('chain_refreshed_at', 'supermarket');

        $cutoff = now()->subHours(self::MAX_AGE_HOURS);
        $chains = [];

        foreach (CheckjebonChain::query()->get() as $chain) {
            if (! SupermarketChains::isLinkable($chain->chain)) {
                continue;
            }

            $refreshedAt = $freshness->get($chain->chain);

            if (! is_string($refreshedAt) || now()->parse($refreshedAt)->lt($cutoff)) {
                continue;
            }

            $chains[$chain->chain] = $chain;
        }

        return $chains;
    }

    /**
     * One token set per distinct pack size the product's shops report, or a
     * single title-only set. Sizes live on the shop, not the product, and two
     * shops can disagree — a 150 g and a 250 g pack — so each gets its own
     * pass, and each chain keeps its best row per tracked size
     * ({@see bestPerChain()}).
     *
     * @return list<QueryTokens>
     */
    public function queriesFor(Product $product): array
    {
        $title = (string) $product->title;

        if (QueryTokens::of($title)->isEmpty()) {
            return [];
        }

        $sets = [];

        foreach ($product->shops as $shop) {
            $size = $this->packSizeOf($shop);

            if (! $size instanceof PackSize) {
                continue;
            }

            $sets[$size->quantity . $size->unit] = QueryTokens::of($title, $size);
        }

        return $sets === [] ? [QueryTokens::of($title)] : array_values($sets);
    }

    private function packSizeOf(Shop $shop): ?PackSize
    {
        if ($shop->pack_quantity === null || $shop->pack_unit === null) {
            return null;
        }

        return PackSize::of((float) $shop->pack_quantity, $shop->pack_unit);
    }

    /**
     * Rows worth scoring: those that pass a query's word prefilter
     * ({@see QueryTokens::prefilter()}) or carry one of the product's
     * barcodes, without dismissed rows. A row whose page a check found gone
     * ({@see CatalogueLinks}) is kept, marked `link_gone`, so the chain's
     * next-best row can stand in and a lost match can be looked up again.
     *
     * @param  array<string, CheckjebonChain>  $chains
     * @param  list<QueryTokens>  $queries
     * @param  list<string>  $gtins
     * @return Collection<int, CheckjebonPrice>
     */
    private function candidateRows(Product $product, array $chains, array $queries, array $gtins): Collection
    {
        $conditions = array_values(array_filter(array_map(static fn (QueryTokens $query): ?array => $query->prefilter(), $queries)));

        if ($conditions === []) {
            /** @var Collection<int, CheckjebonPrice> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<int, CheckjebonPrice> $rows */
        $rows = CheckjebonPrice::query()
            ->select('checkjebon_prices.*')
            ->addSelect([
                'live_url' => CatalogueLink::query()->select('url')->whereColumn('chain', 'checkjebon_prices.supermarket')->whereColumn('external_id', 'checkjebon_prices.external_id')->where('alive', true)->limit(1),
                'link_gone' => CatalogueLink::query()->selectRaw('1')->whereColumn('chain', 'checkjebon_prices.supermarket')->whereColumn('external_id', 'checkjebon_prices.external_id')->where('alive', false)->where('checked_at', '>=', CatalogueLink::validFrom())->limit(1),
            ])
            ->whereIn('supermarket', array_keys($chains))
            ->where(function (EloquentQueryBuilder $query) use ($conditions, $gtins): void {
                foreach ($conditions as [$condition, $bindings]) {
                    $query->orWhereRaw($condition, $bindings);
                }

                if ($gtins !== []) {
                    $query->orWhereIn('ean', $gtins);
                }
            })
            ->when(
                $this->dismissedPairs($product),
                function (EloquentQueryBuilder $query, array $dismissed): void {
                    // A pair-wise NOT rather than a concatenated key: string
                    // concatenation syntax differs per database, and this
                    // list is short by construction.
                    foreach ($dismissed as [$chain, $externalId]) {
                        $query->whereNot(function (EloquentQueryBuilder $inner) use ($chain, $externalId): void {
                            $inner->where('supermarket', $chain)->where('external_id', $externalId);
                        });
                    }
                },
            )
            ->get();

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function dismissedPairs(Product $product): array
    {
        /** @var list<array{0: string, 1: string}> $pairs */
        $pairs = ShopSuggestionDismissal::query()
            ->where('product_id', $product->id)
            ->get(['chain', 'external_id'])
            ->map(static fn (ShopSuggestionDismissal $row): array => [$row->chain, $row->external_id])
            ->all();

        return $pairs;
    }

    /**
     * The best row per chain and per pack size the product is tracked in:
     * a product tracked in 500 ml and 1 l gets a suggestion for each size a
     * chain sells, not only the one that happens to sort first.
     *
     * @param  Collection<int, CheckjebonPrice>  $rows
     * @param  array<string, CheckjebonChain>  $chains
     * @param  list<QueryTokens>  $queries
     * @param  list<string>  $gtins
     * @return array<string, ShopSuggestion>
     */
    private function bestPerChain(Collection $rows, array $chains, array $queries, array $gtins, SuggestionVerdicts $verdicts): array
    {
        $best = [];

        foreach ($rows as $row) {
            $chain = $chains[$row->supermarket] ?? null;
            $link = $row->link;

            if (! $chain instanceof CheckjebonChain || ! is_string($link) || $link === '' || $row->getAttribute('link_gone') !== null) {
                continue;
            }

            [$score, $query, $sameSize] = RowScore::of($row, $queries, $gtins);

            if ($score < $verdicts->floor() || ! $verdicts->admits($chain, $row, $score)) {
                continue;
            }

            // A row in a tracked size counts per size; a row in another size
            // only as the chain's fallback when it has none in a tracked size.
            $key = $sameSize ? "{$row->supermarket}|{$query}" : $row->supermarket;
            $current = $best[$key] ?? null;

            // Equal scores break on external id, so the list is stable
            // between renders.
            if ($current instanceof ShopSuggestion
                && ($score < $current->score
                    || ($score === $current->score && strcmp($row->external_id, $current->externalId) >= 0))) {
                continue;
            }

            $best[$key] = new ShopSuggestion(
                chain: $chain->chain,
                chainLabel: $chain->label,
                externalId: $row->external_id,
                name: $row->name,
                size: $row->size !== null && $row->size !== '' ? $row->size : null,
                price: number_format((float) $row->price, 2, '.', ''),
                url: is_string($row->getAttribute('live_url')) ? $row->getAttribute('live_url') : $chain->productUrl($link),
                score: $score,
                trackable: SupermarketChains::isTrackable($chain->chain),
                checked: $verdicts->confirmed($row),
            );
        }

        foreach (array_keys($best) as $key) {
            if (! str_contains($key, '|') && array_any(array_keys($best), static fn (string $other): bool => str_starts_with($other, "{$key}|"))) {
                unset($best[$key]);
            }
        }

        return $best;
    }

    /**
     * The barcodes the product's shops report, in the form the catalogue
     * stores them: leading zeros stripped, so an EAN-13 and its UPC-12 match.
     *
     * @return list<string>
     */
    public static function gtinsOf(Product $product): array
    {
        return array_values(array_unique(array_map(
            static fn (string $gtin): string => ltrim($gtin, '0'),
            WebShopFinding::trackedGtins($product),
        )));
    }

    /**
     * Whether a catalogue name would score high enough to be offered for one
     * of the queries, with or without the AI check.
     *
     * @param  list<QueryTokens>  $queries
     */
    public function couldOffer(string $name, array $queries): bool
    {
        [$score] = RowScore::of(new CheckjebonPrice(['name' => $name, 'size' => null]), $queries, []);

        return $score >= min(self::THRESHOLD, Config::float('dipcatch.shop_checks.loose_match_from'));
    }

    /**
     * @param  array<string, ShopSuggestion>  $best
     * @return list<ShopSuggestion>
     */
    private function rank(array $best): array
    {
        $suggestions = array_values($best);

        usort(
            $suggestions,
            static fn (ShopSuggestion $a, ShopSuggestion $b): int => $b->score <=> $a->score ?: strcmp($a->chain, $b->chain),
        );

        return $suggestions;
    }
}
