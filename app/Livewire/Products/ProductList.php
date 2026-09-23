<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\PlanLimits;
use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Collection;
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

    /** A department key, a category key, or empty for all. Anything else reads as all. */
    #[Url(except: '')]
    public string $category = '';

    /** Only products in an active drop, or with a deal at their cheapest shop running now. */
    #[Url(except: false)]
    public bool $discounted = false;

    #[Url(except: self::DEFAULT_SORT)]
    public string $sort = self::DEFAULT_SORT;

    private const string DEFAULT_SORT = 'biggest_drop';

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

    public function updatedDiscounted(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.products.product-list', [
            'products' => $this->products(),
            'canAddProduct' => $this->canAddProduct(),
            'categoryGroups' => $this->categoryGroups(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function products(): LengthAwarePaginator
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : self::DEFAULT_SORT;
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
            ->when($this->discounted, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where(
                fn (EloquentQueryBuilder $discount): EloquentQueryBuilder => $discount
                    // The latch DetectDrop sets on an alert and clears on recovery.
                    ->whereNotNull('last_notified_price')
                    ->orWhereHas('cheapestShop', self::dealRunningNow(...)),
            ))
            ->when(
                $categories !== null,
                fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereIn('category', $categories ?? []),
            )
            // The drop a product is in now: its latest alert while the latch is
            // set, measured the way the card's badge is. On a pack basis that
            // is today's price against the alert's reference, and nothing
            // once the price is back; a per-unit alert keeps its own figure.
            ->addSelect(['biggest_drop' => PriceDropEvent::query()
                ->selectRaw(<<<'SQL'
                    CASE
                        WHEN comparison_unit IS NULL AND reference_price > 0 AND products.cheapest_price IS NOT NULL
                            THEN CASE WHEN products.cheapest_price < reference_price
                                THEN (reference_price - products.cheapest_price) * 100 / reference_price END
                        ELSE drop_pct
                    END
                    SQL)
                ->whereColumn('price_drop_events.product_id', 'products.id')
                ->whereNotNull('products.last_notified_price')
                ->latest('fired_at')
                ->latest('id')
                ->limit(1)])
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
                ->whereRaw('ROUND(bundle_total_price / NULLIF(bundle_quantity, 0), 2) = current_price')
                ->whereRaw('ROUND(bundle_total_price / NULLIF(bundle_quantity, 0), 2) < COALESCE(single_item_price, current_price)')));
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

    /**
     * The same string the Filament table rendered: a unit price with its label,
     * or an em dash when no shop in view states a pack size.
     */
    public static function unitPriceState(?Shop $shop, Product $product): string
    {
        $unitPrice = $shop?->unitPrice();

        if ($unitPrice === null) {
            return '—';
        }

        return MoneyFormatter::unitPrice($unitPrice, $product->currency) . ' ' . $shop?->unitPriceLabel();
    }
}
