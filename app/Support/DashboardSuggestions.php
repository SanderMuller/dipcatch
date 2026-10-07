<?php declare(strict_types=1);

namespace App\Support;

use App\Actions\Suggestions\SuggestShops;
use App\Enums\WebFindingStatus;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\ShopSuggestionDismissal;
use App\Models\ShopSuggestionVerdict;
use App\Models\User;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\DiscoveryReach;
use App\Services\Suggestions\ShopSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Suggested shops across an account's products, for the dashboard. With
 * the AI shop check, the most likely matches come first; without it, any
 * suggestion, closest name first.
 *
 * Matching costs a catalogue scan per product, so at most {@see MATCHED}
 * products are matched (with the AI check, the ones with the most likely
 * stored matches first, then those tracked at the fewest shops) and the
 * rows are cached ({@see forUser()}). Listing sends nothing to Jev; a
 * product's own page asks for its answers.
 */
final readonly class DashboardSuggestions
{
    private const int PRODUCTS = 12;

    /** Rows per product, so one product with many shops cannot fill the list. */
    private const int ROWS_PER_PRODUCT = 3;

    /**
     * Products matched at most per render. A stored answer can name a shop
     * that is tracked by now or cannot be added, so some products give no
     * row; the ones after them take their place, up to this many.
     */
    private const int MATCHED = 30;

    private const int ROWS = 12;

    private const int CACHE_MINUTES = 10;

    public function __construct(private SuggestShops $suggest) {}

    /**
     * The rows for the dashboard, kept for a few minutes: matching costs a
     * catalogue scan per product. The key changes when the account adds or
     * removes a shop, pauses a product, hides or shows a shop, dismisses a
     * suggestion, or switches the AI check. Other changes (a title edit, a
     * new AI answer) show within {@see CACHE_MINUTES} minutes.
     *
     * @return list<array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float, otherSize: ?string, viaKlarna: bool, findingId: ?int}>
     */
    public function forUser(User $user): array
    {
        $cached = Cache::remember(
            self::cacheKey($user),
            now()->addMinutes(self::CACHE_MINUTES),
            // Scalars only: the production store unserializes no objects, so
            // a model or a date comes back as an incomplete class.
            fn (): array => array_map(static fn (array $row): array => ['product' => $row['product']->id, 'checkedOn' => $row['checkedOn']?->toIso8601String()] + $row, $this->compute($user)),
        );

        $products = Product::query()
            ->with(['user', 'shops', 'cheapestShop'])
            ->whereKey(array_column($cached, 'product'))
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($cached as $row) {
            $product = $products->get($row['product']);

            if ($product instanceof Product) {
                $rows[] = ['product' => $product, 'checkedOn' => $row['checkedOn'] === null ? null : CarbonImmutable::parse($row['checkedOn'])] + $row;
            }
        }

        return $rows;
    }

    /** The parts of the account the cache key follows. */
    private static function cacheKey(User $user): string
    {
        $shops = DB::table('shops')
            ->join('products', 'products.id', '=', 'shops.product_id')
            ->where('products.user_id', $user->id)
            ->selectRaw('count(*) as shop_count, max(shops.created_at) as last_shop, count(distinct case when products.active then products.id end) as active_products')
            ->first();
        $products = Product::query()->where('user_id', $user->id)->select('id');

        $fingerprint = [
            (array) $shops,
            HiddenShop::query()->where('user_id', $user->id)->orderBy('id')->pluck('id')->all(),
            ShopSuggestionDismissal::query()->whereIn('product_id', $products)->max('id'),
            WebShopFinding::query()->whereIn('product_id', $products)->whereNotNull('dismissed_at')->count(),
            $user->wantsShopChecks(),
        ];

        // v2: earlier entries held objects the store cannot rebuild.
        return "dashboard-suggestions:v2:{$user->id}:" . hash('sha256', (string) json_encode($fingerprint));
    }

    /**
     * @return list<array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float, otherSize: ?string, viaKlarna: bool, findingId: ?int}>
     */
    private function compute(User $user): array
    {
        $aiChecked = $user->wantsShopChecks();
        $ids = $this->productIds($user, $aiChecked);
        $products = Product::query()
            ->with(['user', 'shops', 'cheapestShop'])
            ->whereKey($ids)
            ->get()
            ->sortBy(static fn (Product $product): int|false => array_search($product->id, $ids, strict: true));

        $chances = $aiChecked ? self::storedChances($ids) : [];
        $rows = [];
        $filled = 0;

        foreach ($products as $product) {
            $productRows = $this->rowsFor($product, $aiChecked, $chances);

            if ($productRows === []) {
                continue;
            }

            array_push($rows, ...$productRows);

            if (++$filled === self::PRODUCTS) {
                break;
            }
        }

        usort($rows, self::byLikelihood(...));

        return array_slice($rows, 0, self::ROWS);
    }

    /**
     * One row per shop for a product, the surer of the dataset and the web
     * row, at most {@see ROWS_PER_PRODUCT}, most likely first.
     *
     * @param  array<string, float>  $chances
     * @return list<array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float, otherSize: ?string, viaKlarna: bool, findingId: ?int}>
     */
    private function rowsFor(Product $product, bool $aiChecked, array $chances): array
    {
        $productRows = [];

        foreach (($this->suggest)($product, verify: false) as $suggestion) {
            // One row per shop here, the best-ranked of its pack sizes.
            if ($suggestion->trackable) {
                $row = self::datasetRow($product, $suggestion, $chances);
                $productRows[$row['host']] ??= $row;
            }
        }

        foreach ($aiChecked ? WebShopFinding::shownFor($product) : [] as $finding) {
            $row = self::webRow($product, $finding);

            if (($productRows[$row['host']]['chance'] ?? -1.0) < $row['chance']) {
                $productRows[$row['host']] = $row;
            }
        }

        $productRows = array_values($productRows);
        usort($productRows, self::byLikelihood(...));

        return array_slice($productRows, 0, self::ROWS_PER_PRODUCT);
    }

    /**
     * Most likely first: an AI answer before none, then the closer name.
     *
     * @param  array{chance: ?float, score: float, ...}  $a
     * @param  array{chance: ?float, score: float, ...}  $b
     */
    private static function byLikelihood(array $a, array $b): int
    {
        return ($b['chance'] ?? -1.0) <=> ($a['chance'] ?? -1.0) ?: $b['score'] <=> $a['score'];
    }

    /**
     * The products to match, in order, at most {@see MATCHED} of them.
     *
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
            ->limit(self::MATCHED - count($ids))
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
            ->whereNotIn('host', HiddenShop::hostsOf($user))
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

        return array_slice(array_map(strval(...), array_keys($best)), 0, self::MATCHED);
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private static function eligible(User $user): EloquentQueryBuilder
    {
        return Product::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            // The currency discovery searches the owner's country in.
            ->where('currency', DiscoveryReach::currencyFor($user) ?? '')
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
     * @return array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: ?float, score: float, otherSize: ?string, viaKlarna: bool, findingId: ?int}
     */
    private static function datasetRow(Product $product, ShopSuggestion $suggestion, array $chances): array
    {
        $size = PackSize::resolve($suggestion->size, authoritative: false, title: $suggestion->name);
        $unitPrice = $size?->unitPriceFor($suggestion->price);

        return [
            'product' => $product,
            'shop' => HiddenShop::displayName($suggestion->chainLabel),
            'host' => UrlNormalizer::normalizeHost((string) parse_url($suggestion->url, PHP_URL_HOST)),
            'url' => $suggestion->url,
            'name' => $suggestion->name,
            'unitPrice' => $unitPrice === null || $size === null ? null : MoneyFormatter::unitPrice($unitPrice, 'EUR') . ' ' . $size->label(),
            'packPrice' => PackLine::format($suggestion->price, 'EUR', $size),
            'checkedOn' => null,
            'barcode' => false,
            'otherSize' => null,
            'viaKlarna' => false,
            'findingId' => null,
            'chance' => $suggestion->checked ? ($chances["{$product->id}|{$suggestion->chain}|{$suggestion->externalId}"] ?? null) : null,
            'score' => $suggestion->score,
        ];
    }

    /**
     * @return array{product: Product, shop: string, host: string, url: string, name: string, unitPrice: ?string, packPrice: ?string, checkedOn: ?CarbonImmutable, barcode: bool, chance: float, score: float, otherSize: ?string, viaKlarna: bool, findingId: ?int}
     */
    private static function webRow(Product $product, WebShopFinding $finding): array
    {
        $host = $finding->addHost();
        $size = $finding->page_pack_quantity !== null && $finding->page_pack_unit !== null
            ? PackSize::of((float) $finding->page_pack_quantity, $finding->page_pack_unit)
            : null;
        $currency = $finding->page_currency ?? $product->currency;
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
            'otherSize' => $finding->otherSizeNote($product),
            'viaKlarna' => $finding->isLead(),
            'findingId' => $finding->id,
            'chance' => $chance,
            'score' => $chance,
        ];
    }
}
