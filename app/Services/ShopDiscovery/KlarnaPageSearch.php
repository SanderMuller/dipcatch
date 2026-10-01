<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\Product;
use App\Models\WebSearch;
use App\Services\TypeSafe\ShopCheckOutcome;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use Illuminate\Support\Facades\Config;

/**
 * Searches for a product's Klarna page: one search on Klarna's product pages,
 * and one same-product check on the results, which include the product's
 * siblings (other colours, sizes, sets). See specs/klarna-shop-leads.md §5.1.
 */
final readonly class KlarnaPageSearch
{
    /** The search ran and every result was judged: the product is not on Klarna. */
    public const string NONE = 'none';

    /** A spent search limit, a busy search lock or a spent AI budget: wait for the next run. */
    public const string DEFERRED = 'deferred';

    /** A failed search or check, or one that judged only some results: an attempt. */
    public const string FAILED = 'failed';

    public function __construct(
        private WebSearches $searches,
        private ShopMatchCheck $shopMatch,
    ) {}

    /**
     * The page and the search that found it, or why there is none: one of
     * {@see self::NONE}, {@see self::DEFERRED} or {@see self::FAILED}.
     *
     * @return array{url: string, searchId: int}|string
     */
    public function for(Product $product): array|string
    {
        $country = Config::string('dipcatch.web_discovery.country');
        $outcome = $this->searches->lookUp("site:klarna.com/{$country}/shopping/pl {$product->title}");

        if (! $outcome->search instanceof WebSearch) {
            return $outcome->isDeferred() ? self::DEFERRED : self::FAILED;
        }

        $results = self::klarnaResults($outcome->search);

        if ($results === []) {
            return self::NONE;
        }

        $candidates = [];

        foreach ($results as $index => $result) {
            $candidates["k{$index}"] = ShopMatchCheck::candidate(shop: 'klarna.com', title: $result['title'], packSize: null, price: null, snippet: $result['snippet']);
        }

        $check = $this->shopMatch->askOutcome($product, ShopCheckPurpose::WebDiscovery, $candidates, quick: false, anyPackKeys: array_keys($candidates));

        if ($check->reason !== ShopCheckOutcome::ANSWERED || $check->answers === []) {
            return $check->isDeferred() ? self::DEFERRED : self::FAILED;
        }

        $answers = $check->answers;
        arsort($answers);
        $bestKey = (string) array_key_first($answers);

        if ($answers[$bestKey] >= Config::float('dipcatch.web_discovery.read_from')) {
            return ['url' => $results[(int) substr($bestKey, 1)]['link'], 'searchId' => $outcome->search->id];
        }

        // None passed. With some results left out, they are asked again.
        return count($answers) === count($candidates) ? self::NONE : self::FAILED;
    }

    /**
     * The Klarna product pages among a search's results, best first.
     *
     * @return list<array{title: string, link: string, snippet: string, position: int}>
     */
    public static function klarnaResults(?WebSearch $search): array
    {
        return array_values(array_filter($search->results ?? [], static fn (array $result): bool => KlarnaLeads::isKlarnaPage($result['link'])));
    }
}
