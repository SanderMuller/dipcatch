<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\BillingGate;
use App\Billing\PlanLimits;
use App\Billing\ProPrice;
use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;
use App\Livewire\ShoppingList\HeaderMenu;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\DashboardDigest;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The product list.
 *
 * Deliberately not a port of the Filament table: bulk actions and the ternary
 * filter are dropped per the spec. Sorting and one search box stay, because
 * those are what a person tracking a few dozen products actually uses.
 *
 * Every query is scoped to the signed-in user — the Filament resource did this
 * in `getEloquentQuery()` and nothing else enforced it. The list changes
 * nothing: pausing and resuming live on the product page.
 */
final class ProductList extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all, active or paused. Anything else reads as all. */
    #[Url(except: 'all')]
    public string $status = 'all';

    /** A department key, a category key, NO_CATEGORY, or empty for all. Anything else reads as all. */
    #[Url(except: '')]
    public string $category = '';

    /** A shop host, or empty for all. Products tracked at that shop. */
    #[Url(except: '')]
    public string $shop = '';

    /** Only products in an active drop, or with a deal running now at their cheapest or best-value shop. */
    #[Url(except: false)]
    public bool $discounted = false;

    /** With a shop chosen: only the products that shop is the best buy for, as the dashboard counts them. */
    #[Url(except: false)]
    public bool $bestBuy = false;

    /** Only the products on the shopping list, crossed off or not. */
    #[Url(except: false)]
    public bool $onList = false;

    #[Url(except: self::DEFAULT_SORT)]
    public string $sort = self::DEFAULT_SORT;

    private const string DEFAULT_SORT = 'biggest_drop';

    /**
     * The group of a product sorted by drop. 0: an active product at or
     * under its alert price, compared as {@see Product::isAtTarget()} does,
     * on the stored best value; 1: in a drop, or with a deal as the "Only
     * discounts" switch reads one; 2: the rest.
     */
    private const string LIST_GROUP = <<<'SQL'
        CASE
            WHEN active = TRUE AND (
                (target_price IS NOT NULL AND cheapest_price IS NOT NULL AND cheapest_price <= target_price)
                OR (unit_price_target IS NOT NULL AND best_value_price > 0 AND best_value_pack_quantity > 0
                    AND ROUND(best_value_price * 1.0 / best_value_pack_quantity
                        * (CASE best_value_pack_unit WHEN 'piece' THEN 1 ELSE 1000 END), 6) <= unit_price_target)
            ) THEN 0
            WHEN biggest_drop >= 0.5 OR deals_now > 0 THEN 1
            ELSE 2
        END
        SQL;

    /** @var list<string>|null Memo for {@see bestBuyIds()}; not public, so it lives for one request. */
    private ?array $bestBuyIds = null;

    /** The sort this browser last chose, read before the first render. */
    private const string SORT_COOKIE = 'products_sort';

    /** The filter for products without a category. No category or department uses this key. */
    public const string NO_CATEGORY = 'none';

    /**
     * The sort options, each with the direction that makes it read the way its
     * label promises: biggest drop and newest first, but A before Z.
     *
     * Sorting used to live on the table headers, where it asked a person to
     * know that "Best price" was clickable and to guess what a second click
     * did. One named choice states both the column and the direction.
     *
     * @var array<string, 'asc'|'desc'>
     */
    private const array SORTS = [
        'biggest_drop' => 'desc',
        'created_at' => 'desc',
        'title' => 'asc',
        'cheapest_price' => 'asc',
    ];

    /**
     * A sort in the URL wins; otherwise the one this browser last chose.
     * Read here, on the server, so the first render is already in that
     * order — restoring it in the browser re-sorted the list after it drew.
     */
    public function mount(): void
    {
        if ($this->sort !== self::DEFAULT_SORT) {
            return;
        }

        $remembered = request()->cookie(self::SORT_COOKIE);

        if (is_string($remembered) && array_key_exists($remembered, self::SORTS)) {
            $this->sort = $remembered;
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedShop(): void
    {
        $this->resetPage();

        // The best-buy filter means nothing without a shop.
        if ($this->shop === '') {
            $this->bestBuy = false;
        }
    }

    public function updatedDiscounted(): void
    {
        $this->resetPage();
    }

    private function bestBuyFilterOn(): bool
    {
        return $this->bestBuy && $this->shop !== '';
    }

    public function updatedBestBuy(): void
    {
        $this->resetPage();
    }

    public function updatedOnList(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();

        if (array_key_exists($this->sort, self::SORTS)) {
            Cookie::queue(self::SORT_COOKIE, $this->sort, 60 * 24 * 365);
        }
    }

    /** A change made in the header menu, so a card does not say "On list" for a removed product. */
    #[On('shopping-list-changed')]
    public function refreshList(): void {}

    /**
     * The card's shopping-list button. Only this account's products: a value
     * that is not a UUID answers 404 before any query, as Postgres would
     * reject it in a uuid comparison.
     */
    public function toggleShoppingList(mixed $productId): void
    {
        abort_unless(is_string($productId) && Str::isUuid($productId), 404);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $product = $user->products()->findOrFail($productId);

        if ($product->isOnShoppingList()) {
            $product->removeFromShoppingList();
            Flux::toast(text: __(':title is off the list.', ['title' => $product->title]));
        } else {
            $product->addToShoppingList();
            Flux::toast(text: __(':title is on your shopping list.', ['title' => $product->title]));
        }

        $this->dispatch('shopping-list-changed')->to(HeaderMenu::class);
    }

    public function render(): View
    {
        $showAutoCategoriesPromo = $this->category === self::NO_CATEGORY && $this->canBuyAutoCategories();

        return view('livewire.products.product-list', [
            'products' => $this->products(),
            'groupSizes' => $this->groupSizes(),
            'canAddProduct' => $this->canAddProduct(),
            'categoryGroups' => $this->categoryGroups(),
            'shopHosts' => $this->shopHosts(),
            // Kept while selected, for the same reason as categoryGroups().
            'hasUncategorised' => $this->category === self::NO_CATEGORY || $this->uncategorised()->exists(),
            // Kept while on, so the switch that emptied the list can turn it off.
            'hasListed' => $this->onList || Product::query()->where('user_id', auth()->id())->onShoppingList()->exists(),
            'showAutoCategoriesPromo' => $showAutoCategoriesPromo,
            // "Try" only when checkout starts a free trial for this account.
            'promoOffersTrial' => $showAutoCategoriesPromo && ProPrice::trialDays() > 0 && auth()->user()?->qualifiesForTrial() === true,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function products(): LengthAwarePaginator
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : self::DEFAULT_SORT;

        // By drop, the groups come first: at the alert price, then on
        // discount, then the rest.
        $query = $sort === 'biggest_drop'
            ? $this->grouped()->orderBy('list_group')
            : $this->filtered()->addSelect(['biggest_drop' => Product::liveDropPercentQuery()]);

        return $query
            ->with(['cheapestShop', 'shops', 'latestPriceDropEvent'])
            // A product not in a drop, or with no price yet, sorts last
            // whichever way the list runs, rather than heading a list of
            // drops with rows that have none.
            ->orderByRaw(self::orderBy($sort))
            // A tie has to break the same way every time, or a row can appear
            // on two pages and another on none. The ids are UUIDv7, so this
            // also reads as newest first.
            ->orderBy('id', 'desc')
            // 24 fills whole rows at one, two, three and four cards across.
            ->paginate(24);
    }

    /**
     * The account's products under the list's search and filters, before
     * sorting and paging.
     *
     * @return EloquentQueryBuilder<Product>
     */
    private function filtered(): EloquentQueryBuilder
    {
        $categories = ProductCategory::leavesFor($this->category);

        return Product::query()
            ->where('user_id', auth()->id())
            // LOWER() on both sides: Postgres LIKE is case-sensitive, so a
            // plain `like '%arabica%'` never matches "Arabica beans".
            ->when($this->search !== '', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereRaw(
                'LOWER(title) LIKE ?',
                ['%' . mb_strtolower($this->search) . '%'],
            ))
            ->when(
                in_array($this->status, ['active', 'paused'], strict: true),
                fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('active', $this->status === 'active'),
            )
            // With best buys on, the offers come from the same rule as the dashboard badge, below.
            ->when($this->discounted && ! $this->bestBuyFilterOn(), fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where(
                fn (EloquentQueryBuilder $discount): EloquentQueryBuilder => $this->shop === ''
                    ? self::discountedAnywhere($discount)
                    : self::discountedAt($discount, $this->shop),
            ))
            ->when(
                $categories !== null,
                fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereIn('category', $categories ?? []),
            )
            ->when($this->onList, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->onShoppingList())
            ->when($this->category === self::NO_CATEGORY, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('category'))
            ->when($this->bestBuyFilterOn(), fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereKey($this->bestBuyIds()))
            ->when($this->shop !== '', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereHas(
                'shops',
                fn (EloquentQueryBuilder $shops): EloquentQueryBuilder => $shops->where('host', $this->shop)->where('active', true),
            ));
    }

    /**
     * The best buys at the chosen shop, read once per request: the list and
     * the drop count both filter on them, and each read loads the account's
     * products and shops.
     *
     * @return list<string>
     */
    private function bestBuyIds(): array
    {
        $user = auth()->user();

        return $this->bestBuyIds ??= $user instanceof User ? DashboardDigest::bestBuyIds($user, $this->shop, onOfferOnly: $this->discounted) : [];
    }

    /**
     * The filtered products with what the sort and the groups read: the live
     * drop, the deals running at the shops a card names, and the group.
     * Wrapped, so the group can be computed from the two subqueries.
     *
     * @return EloquentQueryBuilder<Product>
     */
    private function grouped(): EloquentQueryBuilder
    {
        // With a shop chosen, a deal there counts, as the "Only discounts"
        // switch has it; otherwise a deal at a shop the card names.
        $shops = $this->shop === ''
            ? Shop::query()->where(fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop
                ->whereColumn('shops.id', 'products.cheapest_shop_id')
                // A string column beside a uuid key: cast for PostgreSQL.
                ->orWhereRaw('CAST(shops.id AS TEXT) = products.best_value_shop_id'))
            : Shop::query()->whereColumn('shops.product_id', 'products.id')->where('host', $this->shop)->where('active', true);

        $inner = $this->filtered()->addSelect([
            'biggest_drop' => Product::liveDropPercentQuery(),
            'deals_now' => self::dealRunningNow($shops->selectRaw('COUNT(*)')),
        ]);

        return Product::query()->fromSub($inner, 'products')->select('*')->selectRaw(self::LIST_GROUP . ' AS list_group');
    }

    /**
     * How many products sit in each group, when the list is sorted by drop:
     * a line goes where a group starts. Counted, not read off the page, so
     * the line still shows when a group ends on the last card of a page.
     *
     * @return array{0: int, 1: int, 2: int}|null at the alert price, on discount, the rest
     */
    private function groupSizes(): ?array
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : self::DEFAULT_SORT;

        if ($sort !== 'biggest_drop') {
            return null;
        }

        $sizes = Product::query()->fromSub($this->grouped(), 'grouped')
            ->selectRaw('list_group, COUNT(*) AS size')
            ->groupBy('list_group')
            ->pluck('size', 'list_group');

        $size = static fn (int $group): int => is_numeric($sizes[$group] ?? null) ? (int) $sizes[$group] : 0;

        return [$size(0), $size(1), $size(2)];
    }

    /**
     * At the alert price, in a drop, or with a deal at either shop a card
     * names: the lowest price, or the best value it leads with.
     *
     * @param  EloquentQueryBuilder<Product>  $query
     * @return EloquentQueryBuilder<Product>
     */
    private static function discountedAnywhere(EloquentQueryBuilder $query): EloquentQueryBuilder
    {
        return $query
            ->where(fn (EloquentQueryBuilder $alert): EloquentQueryBuilder => $alert->atTarget())
            ->orWhere(fn (EloquentQueryBuilder $drop): EloquentQueryBuilder => $drop->inVisibleDrop())
            ->orWhereHas('cheapestShop', self::dealRunningNow(...))
            // A string column beside a uuid key: cast for PostgreSQL.
            ->orWhereExists(self::dealRunningNow(
                Shop::query()->select(DB::raw(1))->whereRaw('CAST(shops.id AS TEXT) = products.best_value_shop_id'),
            ));
    }

    /**
     * On discount at this shop itself: a deal running there, or a drop to the
     * price there, which is the lowest price or the best value.
     *
     * @param  EloquentQueryBuilder<Product>  $query
     * @return EloquentQueryBuilder<Product>
     */
    private static function discountedAt(EloquentQueryBuilder $query, string $host): EloquentQueryBuilder
    {
        return $query
            ->whereHas('shops', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => self::dealRunningNow($shop->where('host', $host)->where('active', true)))
            ->orWhere(fn (EloquentQueryBuilder $drop): EloquentQueryBuilder => $drop
                ->inVisibleDrop()
                ->where(fn (EloquentQueryBuilder $there): EloquentQueryBuilder => $there
                    ->whereHas('cheapestShop', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop->where('host', $host))
                    ->orWhereExists(Shop::query()
                        ->select(DB::raw(1))
                        ->whereRaw('CAST(shops.id AS TEXT) = products.best_value_shop_id')
                        ->where('host', $host))));
    }

    /**
     * A deal at the shop running now, in SQL: a promotion window that has
     * started and not ended, or a bundle offer the price was read at. The
     * same rules as PromotionWindow::isRunning() and Shop::liveBundleOffer().
     *
     * @param  EloquentQueryBuilder<Shop>  $shop
     * @return EloquentQueryBuilder<Shop>
     */
    private static function dealRunningNow(EloquentQueryBuilder $shop): EloquentQueryBuilder
    {
        $now = now();

        return $shop->where(fn (EloquentQueryBuilder $deal): EloquentQueryBuilder => $deal
            ->where(fn (EloquentQueryBuilder $promotion): EloquentQueryBuilder => $promotion
                ->where('promotion_ends_at', '>=', $now)
                ->where(fn (EloquentQueryBuilder $started): EloquentQueryBuilder => $started
                    ->whereNull('promotion_starts_at')
                    ->orWhere('promotion_starts_at', '<=', $now)))
            ->orWhere(fn (EloquentQueryBuilder $bundle): EloquentQueryBuilder => $bundle
                ->where('bundle_quantity', '>=', 2)
                ->where('bundle_total_price', '>=', 0.01)
                // `* 1.0`: SQLite divides two whole numbers as integers.
                ->whereRaw('ROUND(bundle_total_price * 1.0 / NULLIF(bundle_quantity, 0), 2) = current_price')
                ->whereRaw('ROUND(bundle_total_price * 1.0 / NULLIF(bundle_quantity, 0), 2) < COALESCE(single_item_price, current_price)')));
    }

    /**
     * The shops the account tracks a product at, within the category filter:
     * on "Food & drinks", only the shops that carry one of its food products.
     * The selected shop stays offered when the category leaves it out, or
     * the select would show a blank value.
     *
     * @return list<string>
     */
    private function shopHosts(): array
    {
        $categories = ProductCategory::leavesFor($this->category);

        $hosts = Shop::query()
            ->where('active', true)
            ->whereHas('product', fn (EloquentQueryBuilder $product): EloquentQueryBuilder => $product
                ->where('user_id', auth()->id())
                ->when($categories !== null, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereIn('category', $categories ?? []))
                ->when($this->category === self::NO_CATEGORY, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('category')))
            ->distinct()
            ->orderBy('host')
            ->pluck('host')
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '')
            ->values()
            ->all();

        if ($this->shop !== '' && ! in_array($this->shop, $hosts, strict: true)) {
            $hosts[] = $this->shop;
        }

        return $hosts;
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private function uncategorised(): EloquentQueryBuilder
    {
        return Product::query()->where('user_id', auth()->id())->whereNull('category');
    }

    /**
     * Whether Pro would sort these products: the feature is switched on here,
     * this account can buy Pro (the billing page's own rule), and does not
     * have it yet. A Pro account is not sold what it already pays for.
     */
    private function canBuyAutoCategories(): bool
    {
        $user = auth()->user();

        return TypeSafeClient::configured()
            && BillingGate::isOpen()
            && $user instanceof User
            && $user->billing_blocked_at === null
            && ! $user->entitlements()->allowsAutoCategories();
    }

    /**
     * The current filter stays offered even when its last product was just
     * cleared, or the select would show a blank value.
     *
     * @return array<string, list<ProductCategory>> department value => categories the account uses
     */
    private function categoryGroups(): array
    {
        /** @var Collection<int, ProductCategory> $used the column is cast, so pluck() yields enums */
        $used = Product::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        $selectedCategory = ProductCategory::tryFrom($this->category);
        $selectedDepartment = ProductDepartment::tryFrom($this->category);

        if ($selectedCategory !== null) {
            $used->push($selectedCategory);
        }

        $groups = [];

        foreach (ProductCategory::grouped() as $department => $categories) {
            $offered = array_values(array_filter(
                $categories,
                static fn (ProductCategory $category): bool => $used->contains($category),
            ));

            if ($offered !== [] || $department === $selectedDepartment?->value) {
                $groups[$department] = $offered;
            }
        }

        return $groups;
    }

    /**
     * The sort keys the view offers. The browser checks a remembered sort
     * against these before it applies it.
     *
     * @return list<string>
     */
    public static function sortOptions(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * The ORDER BY for one sort key, written out rather than assembled: the
     * query builder takes a literal string here, and a clause built from
     * parts is exactly what that rule is guarding against.
     *
     * Empty values go last either way, so a list of drops does not open with
     * rows that have none, and a list by price does not open with rows that
     * have no price yet.
     *
     * @return literal-string
     */
    private static function orderBy(string $sort): string
    {
        return match ($sort) {
            // Postgres orders capitals before lowercase, so "apple" would
            // follow "Zest" without this.
            'title' => 'LOWER(title) asc',
            'cheapest_price' => 'cheapest_price asc NULLS LAST',
            'biggest_drop' => 'biggest_drop desc NULLS LAST',
            default => 'created_at desc',
        };
    }

    private function canAddProduct(): bool
    {
        $user = auth()->user();

        return ! $user instanceof User || app(PlanLimits::class)->canAddProduct($user);
    }
}
