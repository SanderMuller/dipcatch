<?php declare(strict_types=1);

namespace App\Support;

use App\Actions\Suggestions\SuggestShops;
use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\ShopSuggestionVerdict;
use App\Models\User;
use App\Models\WebShopFinding;
use App\Services\Suggestions\ShopSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Config;

/**
 * Suggested shops across an account's products, for the dashboard. With
 * the AI shop check, the most likely matches come first; without it, any
 * suggestion, closest name first.
 *
 * Matching the daily dataset costs tens of milliseconds a product, so only
 * a few products are matched per render: with the AI check, the ones with
 * the most likely stored matches, then those tracked at the fewest shops.
 * Listing sends nothing to Jev; a product's own page asks for its answers.
 */
final readonly class DashboardSuggestions
{
    private const int PRODUCTS = 10;

    private const int ROWS = 8;

    public function __construct(private SuggestShops $suggest) {}

    /**
     * @return list<array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float}>
     */
    public function forUser(User $user): array
    {
        $aiChecked = $user->wantsShopChecks();
        $products = Product::query()
            ->with(['user', 'shops', 'cheapestShop'])
            ->whereKey($this->productIds($user, $aiChecked))
            ->get();

        $chances = $aiChecked ? self::storedChances(array_values(array_map(strval(...), $products->modelKeys()))) : [];
        $rows = [];

        foreach ($products as $product) {
            $productRows = [];

            foreach (($this->suggest)($product, verify: false) as $suggestion) {
                if ($suggestion->trackable) {
                    $row = self::datasetRow($product, $suggestion, $chances);
                    $productRows[$row['host']] = $row;
                }
            }

            foreach ($aiChecked ? WebShopFinding::shownFor($product) : [] as $finding) {
                $row = self::webRow($product, $finding);

                // One row per shop: the dataset and the web can both find it.
                if (($productRows[$row['host']]['chance'] ?? -1.0) < $row['chance']) {
                    $productRows[$row['host']] = $row;
                }
            }

            array_push($rows, ...array_values($productRows));
        }

        usort($rows, static fn (array $a, array $b): int => ($b['chance'] ?? -1.0) <=> ($a['chance'] ?? -1.0) ?: $b['score'] <=> $a['score']);

        return array_slice($rows, 0, self::ROWS);
    }

    /**
     * @return list<string>
     */
    private function productIds(User $user, bool $aiChecked): array
    {
        $ids = $aiChecked ? self::mostLikelyMatches($user) : [];

        $fill = self::eligible($user)
            ->whereNotIn('id', $ids)
            ->withCount('shops')
            ->orderBy('shops_count')
            ->latest('created_at')
            ->limit(self::PRODUCTS - count($ids))
            ->pluck('id')
            ->all();

        return [...$ids, ...array_values(array_filter($fill, is_string(...)))];
    }

    /**
     * Products with a stored AI answer at or above the accept line, most
     * likely first. The answers may be stale; matching them again drops those.
     *
     * @return list<string>
     */
    private static function mostLikelyMatches(User $user): array
    {
        $eligible = self::eligible($user)->select('id');

        $web = WebShopFinding::query()
            ->whereIn('product_id', $eligible)
            ->where('status', WebFindingStatus::Proposed)
            ->whereNull('dismissed_at')
            ->groupBy('product_id')
            ->selectRaw('product_id, max(second_chance) as chance')
            ->pluck('chance', 'product_id')
            ->all();

        $dataset = ShopSuggestionVerdict::query()
            ->whereIn('product_id', $eligible)
            ->where('same_chance', '>=', Config::float('dipcatch.shop_checks.accept_from'))
            ->groupBy('product_id')
            ->selectRaw('product_id, max(same_chance) as chance')
            ->pluck('chance', 'product_id')
            ->all();

        $best = [];

        foreach ([$web, $dataset] as $chances) {
            foreach ($chances as $productId => $chance) {
                $best[(string) $productId] = max($best[(string) $productId] ?? 0.0, is_numeric($chance) ? (float) $chance : 0.0);
            }
        }

        arsort($best);

        return array_slice(array_map(strval(...), array_keys($best)), 0, self::PRODUCTS);
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private static function eligible(User $user): EloquentQueryBuilder
    {
        return Product::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->whereRaw('UPPER(currency) = ?', ['EUR'])
            ->has('shops');
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, float>
     */
    private static function storedChances(array $productIds): array
    {
        $chances = [];

        foreach (ShopSuggestionVerdict::query()->whereIn('product_id', $productIds)->get(['product_id', 'chain', 'external_id', 'same_chance']) as $verdict) {
            $chances["{$verdict->product_id}|{$verdict->chain}|{$verdict->external_id}"] = $verdict->same_chance;
        }

        return $chances;
    }

    /**
     * @param  array<string, float>  $chances
     * @return array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float}
     */
    private static function datasetRow(Product $product, ShopSuggestion $suggestion, array $chances): array
    {
        $size = PackSize::resolve($suggestion->size, authoritative: false, title: $suggestion->name);
        $unitPrice = $size?->unitPriceFor($suggestion->price);

        return [
            'product' => $product,
            'shop' => $suggestion->chainLabel,
            'host' => UrlNormalizer::normalizeHost((string) parse_url($suggestion->url, PHP_URL_HOST)),
            'url' => $suggestion->url,
            'name' => $suggestion->name,
            'unitPrice' => $unitPrice === null || $size === null ? null : MoneyFormatter::unitPrice($unitPrice, 'EUR') . ' ' . $size->label(),
            'packPrice' => PackLine::format($suggestion->price, 'EUR', $size),
            'checkedOn' => null,
            'barcode' => false,
            'chance' => $suggestion->checked ? ($chances["{$product->id}|{$suggestion->chain}|{$suggestion->externalId}"] ?? null) : null,
            'score' => $suggestion->score,
        ];
    }

    /**
     * @return array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: float, score: float}
     */
    private static function webRow(Product $product, WebShopFinding $finding): array
    {
        $host = $finding->addHost();
        $size = $finding->page_pack_quantity !== null && $finding->page_pack_unit !== null
            ? PackSize::of((float) $finding->page_pack_quantity, $finding->page_pack_unit)
            : null;
        $currency = $finding->page_currency ?? 'EUR';
        $unitPrice = $finding->page_price === null ? null : $size?->unitPriceFor($finding->page_price);
        $chance = $finding->second_chance ?? 0.0;

        return [
            'product' => $product,
            'shop' => $host,
            'host' => $host,
            'url' => $finding->add_url ?? $finding->url,
            'name' => $finding->page_title ?? $finding->search_title,
            'unitPrice' => $unitPrice === null || $size === null ? null : MoneyFormatter::unitPrice($unitPrice, $currency) . ' ' . $size->label(),
            'packPrice' => $finding->page_price === null ? null : PackLine::format($finding->page_price, $currency, $size),
            'checkedOn' => $finding->read_at ?? $finding->checked_at,
            'barcode' => $finding->matched_gtin !== null,
            'chance' => $chance,
            'score' => $chance,
        ];
    }
}
