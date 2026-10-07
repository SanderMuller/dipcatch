<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Suggestions\CheckSuggestedLinks;
use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Models\Product;
use App\Models\WebSearch;
use App\Services\Checkjebon\CatalogueLinks;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\SupermarketChains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Checks a product's suggested supermarket pages, and looks up on a shop's
 * own site a product whose list page is gone. Queued by
 * {@see CheckSuggestedLinks}.
 */
#[Tries(1)]
#[Timeout(60)]
final class CheckCatalogueLinks implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{chain: string, externalId: string, url: string}>  $toFetch
     * @param  list<array{chain: string, name: string, size: ?string}>  $toSearch
     */
    public function __construct(
        public string $productId,
        public array $toFetch,
        public array $toSearch,
    ) {}

    public function handle(ShopFetcher $fetcher, WebSearches $searches, WebShopDiscovery $discovery): void
    {
        foreach ($this->toFetch as $link) {
            $this->check($fetcher, $link);
        }

        if ($this->toSearch === []) {
            return;
        }

        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        // Asked again: the owner may have left Pro or switched AI help off
        // since the job was queued.
        if (! $product instanceof Product || $product->user === null || ! $discovery->runsForOwner($product->user, $product->currency)) {
            return;
        }

        $found = false;

        foreach ($this->toSearch as $lost) {
            $host = self::hostOf($lost['chain']);
            // The chains of the daily list are Dutch, whoever owns the product.
            $search = $host === null ? null : $searches->forQuery(trim("site:{$host} {$lost['name']} {$lost['size']}"), 'nl');

            if ($search instanceof WebSearch) {
                $found = $discovery->storeFindingsOn($product, $search, $host) || $found;
            }
        }

        // The first check and the page read decide whether a found page shows.
        if ($found) {
            dispatch(new DiscoverWebShops($this->productId));
        }
    }

    /**
     * Only a 404, a 410 or the shop's not-found page means gone. A wall, a
     * rate limit or a timeout says
     * nothing about the page, so it is left unchecked for the next time.
     *
     * @param  array{chain: string, externalId: string, url: string}  $link
     */
    private function check(ShopFetcher $fetcher, array $link): void
    {
        try {
            $page = $fetcher->fetch($link['url']);
        } catch (HttpError $e) {
            if (in_array($e->statusCode, [404, 410], strict: true)) {
                CatalogueLink::record($link['chain'], $link['externalId'], alive: false);
            }

            return;
        } catch (FetchException) {
            return;
        }

        CatalogueLink::record($link['chain'], $link['externalId'], alive: ! CatalogueLinks::isNotFound($page->finalUrl, $page->statusCode));
    }

    private static function hostOf(string $chain): ?string
    {
        $row = CheckjebonChain::query()->where('chain', $chain)->first();

        return $row instanceof CheckjebonChain ? (SupermarketChains::hosts($row->chain, $row->base_url)[0] ?? null) : null;
    }
}
