<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Jobs\CheckCatalogueLinks;
use App\Models\CatalogueLink;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Services\Checkjebon\CatalogueLinks;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\Suggestions\QueryTokens;
use App\Services\Suggestions\RowScore;
use App\Services\Suggestions\ShopSuggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * After the suggestions show, queues what keeps them honest: a fetch of each
 * suggested page that has not been checked, on a shop where one fetch tells,
 * and, with the AI shop check on, a search of the shop's site for a product
 * whose list page is gone. At most once per product per ten minutes, as
 * {@see VerifyShopSuggestions}.
 */
final class CheckSuggestedLinks
{
    /** Site searches per product per run: they spend the discovery's daily searches. */
    private const int MAX_SEARCHES = 2;

    /** Page fetches per product per run, so the job stays well inside its timeout. */
    private const int MAX_FETCHES = 3;

    /**
     * @param  list<ShopSuggestion>  $suggestions
     * @param  list<CheckjebonPrice>  $lost  Well-matched rows whose page is gone, of chains with nothing else to offer.
     */
    public static function afterResponseFor(Product $product, array $suggestions, array $lost): void
    {
        $toFetch = array_slice(self::unchecked($suggestions), 0, self::MAX_FETCHES);
        $toSearch = $lost !== [] && $product->user !== null && app(WebShopDiscovery::class)->runsForOwner($product->user, $product->currency)
            ? array_map(static fn (CheckjebonPrice $row): array => ['chain' => $row->supermarket, 'name' => $row->name, 'size' => $row->size], array_slice($lost, 0, self::MAX_SEARCHES))
            : [];

        if (($toFetch === [] && $toSearch === []) || ! Cache::add("shop-suggestions:links:{$product->id}", true, now()->addMinutes(10))) {
            return;
        }

        // Two jobs, so the fetches and the searches each fit one job's timeout.
        $jobs = array_filter([
            $toFetch === [] ? null : new CheckCatalogueLinks((string) $product->id, $toFetch, []),
            $toSearch === [] ? null : new CheckCatalogueLinks((string) $product->id, [], $toSearch),
        ]);

        // Queued once the response is out, so the page shows first. Through
        // terminating() rather than afterResponse(), for the reason
        // CategoriseProduct gives.
        app()->terminating(static function () use ($jobs): void {
            foreach ($jobs as $job) {
                dispatch($job);
            }
        });
    }

    /**
     * Per chain left without a suggestion, its best-matched row whose page is
     * gone: the shop may sell it under another page.
     *
     * @param  Collection<int, CheckjebonPrice>  $rows
     * @param  list<QueryTokens>  $queries
     * @param  list<string>  $gtins
     * @param  list<ShopSuggestion>  $suggestions
     * @return list<CheckjebonPrice>
     */
    public static function lost(Collection $rows, array $queries, array $gtins, array $suggestions, float $threshold): array
    {
        $offered = array_map(static fn (ShopSuggestion $suggestion): string => $suggestion->chain, $suggestions);
        $lost = [];

        foreach ($rows as $row) {
            if ($row->getAttribute('link_gone') === null || in_array($row->supermarket, $offered, strict: true)) {
                continue;
            }

            [$score] = RowScore::of($row, $queries, $gtins);

            if ($score >= $threshold && ($score > ($lost[$row->supermarket][1] ?? -1.0))) {
                $lost[$row->supermarket] = [$row, $score];
            }
        }

        return array_values(array_map(static fn (array $entry): CheckjebonPrice => $entry[0], $lost));
    }

    /**
     * @param  list<ShopSuggestion>  $suggestions
     * @return list<array{chain: string, externalId: string, url: string}>
     */
    private static function unchecked(array $suggestions): array
    {
        $candidates = array_values(array_filter(
            $suggestions,
            static fn (ShopSuggestion $suggestion): bool => in_array($suggestion->chain, CatalogueLinks::CHECKED_BY_STATUS, strict: true),
        ));

        if ($candidates === []) {
            return [];
        }

        $checked = CatalogueLink::query()
            ->whereIn('external_id', array_map(static fn (ShopSuggestion $suggestion): string => $suggestion->externalId, $candidates))
            ->where('checked_at', '>=', CatalogueLink::validFrom())
            ->get(['chain', 'external_id'])
            ->map(static fn (CatalogueLink $link): string => "{$link->chain}|{$link->external_id}")
            ->all();

        return array_values(array_map(
            static fn (ShopSuggestion $suggestion): array => ['chain' => $suggestion->chain, 'externalId' => $suggestion->externalId, 'url' => $suggestion->url],
            array_filter($candidates, static fn (ShopSuggestion $suggestion): bool => ! in_array("{$suggestion->chain}|{$suggestion->externalId}", $checked, strict: true)),
        ));
    }
}
