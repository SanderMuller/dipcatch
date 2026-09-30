<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionDismissal;
use App\Services\Suggestions\QueryTokens;
use App\Services\Suggestions\ShopSuggestion;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Support\PackSize;
use App\Support\SupermarketChains;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggests other shops for a tracked product by matching its title and pack
 * size against the local checkjebon dataset. The dataset price is a
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
     * Taken off the overlap of a row whose pack size differs from the one
     * it is compared against, in the same unit. The size is two of about
     * eight tokens, so a wrong size alone barely moved the overlap: a 300 g
     * Milka Mmmax bar scored 0.556 against a 100 g Milka bar and was offered.
     * With this, a row of another size needs about 0.65 on its name alone.
     * The same name in a bigger pack still reaches that (0.71 for a
     * four-word name), since per-kilo prices compare across pack sizes.
     */
    private const float OTHER_SIZE_PENALTY = 0.1;

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
     * copy inside the add-shop form), and the catalogue scan costs about
     * 90 ms — paying it twice per page is pure waste. The action is bound
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
     * and queued jobs, but not inside one `artisan` process — nothing loops
     * products through this action today, and a command that did would hold
     * one set, and one freshness cutoff, for its whole run.
     *
     * @var array<string, CheckjebonChain>|null
     */
    private ?array $freshChains = null;

    /** Preferred prefilter token length — shorter needles match half the catalogue. */
    private const int PREFERRED_PREFILTER_TOKEN = 4;

    /** Shortest token still worth a `LIKE`, used when no longer one exists. */
    private const int MIN_PREFILTER_TOKEN = 2;

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
        $queries = $this->queryTokenSets($product);

        if ($chains === [] || $queries === []) {
            return [];
        }

        $verdicts = $this->shopMatch->applies($product)
            ? SuggestionVerdicts::for($product, self::THRESHOLD)
            : SuggestionVerdicts::off(self::THRESHOLD);

        $suggestions = $this->rank($this->bestPerChain($this->candidateRows($product, $chains, $queries), $chains, $queries, $verdicts));

        if ($verify && $verdicts->unchecked() !== []) {
            VerifyShopSuggestions::afterResponseFor($product, $verdicts->unchecked());
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
     * pass and the best score per chain wins.
     *
     * @return list<QueryTokens>
     */
    private function queryTokenSets(Product $product): array
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
     * Rows worth scoring, prefiltered on the longest query token.
     * `lower(name) like ?` rather than a bare `like` — production runs
     * PostgreSQL, where `like` is case-sensitive and a lowercased token
     * would never match a capitalised catalogue name.
     *
     * @param  array<string, CheckjebonChain>  $chains
     * @param  list<QueryTokens>  $queries
     * @return Collection<int, CheckjebonPrice>
     */
    private function candidateRows(Product $product, array $chains, array $queries): Collection
    {
        $needles = [];

        foreach ($queries as $query) {
            // A short title ("7up 1 l") has no four-letter token; falling
            // back to its longest one keeps the product from silently
            // getting no suggestions at all.
            $needle = $query->longestToken(self::PREFERRED_PREFILTER_TOKEN)
                ?? $query->longestToken(self::MIN_PREFILTER_TOKEN);

            if ($needle !== null) {
                $needles[$needle] = true;
            }
        }

        if ($needles === []) {
            /** @var Collection<int, CheckjebonPrice> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<int, CheckjebonPrice> $rows */
        $rows = CheckjebonPrice::query()
            ->whereIn('supermarket', array_keys($chains))
            ->where(function (EloquentQueryBuilder $query) use ($needles): void {
                foreach (array_keys($needles) as $needle) {
                    $query->orWhereRaw('lower(name) like ?', ['%' . $needle . '%']);
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
     * @param  Collection<int, CheckjebonPrice>  $rows
     * @param  array<string, CheckjebonChain>  $chains
     * @param  list<QueryTokens>  $queries
     * @return array<string, ShopSuggestion>
     */
    private function bestPerChain(Collection $rows, array $chains, array $queries, SuggestionVerdicts $verdicts): array
    {
        $best = [];

        foreach ($rows as $row) {
            $chain = $chains[$row->supermarket] ?? null;
            $link = $row->link;

            if (! $chain instanceof CheckjebonChain || ! is_string($link) || $link === '') {
                continue;
            }

            $score = $this->scoreOf($row, $queries);

            if ($score < $verdicts->floor() || ! $verdicts->admits($chain, $row, $score)) {
                continue;
            }

            $current = $best[$row->supermarket] ?? null;

            // Equal scores break on external id, so the list is stable
            // between renders.
            if ($current instanceof ShopSuggestion
                && ($score < $current->score
                    || ($score === $current->score && strcmp($row->external_id, $current->externalId) >= 0))) {
                continue;
            }

            $best[$row->supermarket] = new ShopSuggestion(
                chain: $chain->chain,
                chainLabel: $chain->label,
                externalId: $row->external_id,
                name: $row->name,
                size: $row->size !== null && $row->size !== '' ? $row->size : null,
                price: number_format((float) $row->price, 2, '.', ''),
                url: $chain->productUrl($link),
                score: $score,
                trackable: SupermarketChains::isTrackable($chain->chain),
                checked: $verdicts->confirmed($row),
            );
        }

        return $best;
    }

    /**
     * @param  list<QueryTokens>  $queries
     */
    private function scoreOf(CheckjebonPrice $row, array $queries): float
    {
        $candidate = QueryTokens::ofCatalogueRow($row->name, $row->size);

        if ($candidate->isEmpty()) {
            return 0.0;
        }

        $best = 0.0;

        foreach ($queries as $query) {
            if ($query->sameVariantAs($candidate)) {
                $overlap = $query->overlapWith($candidate);

                if ($query->hasOtherSizeThan($candidate)) {
                    $overlap -= self::OTHER_SIZE_PENALTY;
                }

                $best = max($best, $overlap);
            }
        }

        return $best;
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
