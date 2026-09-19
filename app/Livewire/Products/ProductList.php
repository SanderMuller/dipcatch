<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\PlanLimits;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
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
 * in `getEloquentQuery()` and nothing else enforced it — and every mutation
 * goes through `ProductPolicy`, which the resource documented as its second
 * line of defence.
 */
final class ProductList extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all, active or paused. Anything else reads as all. */
    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: self::DEFAULT_SORT)]
    public string $sort = self::DEFAULT_SORT;

    private const string DEFAULT_SORT = 'created_at';

    /**
     * The sort options, each with the direction that makes it read the way its
     * label promises: newest first, but A before Z.
     *
     * Sorting used to live on the table headers, where it asked a person to
     * know that "Best price" was clickable and to guess what a second click
     * did. One named choice states both the column and the direction.
     *
     * @var array<string, 'asc'|'desc'>
     */
    private const array SORTS = [
        'created_at' => 'desc',
        'title' => 'asc',
        'cheapest_price' => 'asc',
        'biggest_drop' => 'desc',
    ];

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    /**
     * Pausing and resuming are mutations, so they authorize rather than trusting
     * the scoped list the page was rendered from.
     */
    public function togglePaused(string $productId): void
    {
        $product = Product::query()->findOrFail($productId);

        $this->authorize('update', $product);

        $product->forceFill(['active' => ! $product->active])->save();
    }

    public function render(): View
    {
        return view('livewire.products.product-list', [
            'products' => $this->products(),
            'canAddProduct' => $this->canAddProduct(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function products(): LengthAwarePaginator
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : self::DEFAULT_SORT;

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
            ->withCount('shops')
            ->withMax('priceDropEvents as biggest_drop', 'drop_pct')
            ->with(['cheapestShop', 'shops'])
            // A product that never dropped, or has no price yet, sorts last
            // whichever way the list runs, rather than heading a list of
            // drops with rows that have none.
            ->orderByRaw(self::orderBy($sort))
            // A tie has to break the same way every time, or a row can appear
            // on two pages and another on none. The ids are UUIDv7, so this
            // also reads as newest first.
            ->orderBy('id', 'desc')
            ->paginate();
    }

    /** The sort keys a view offers, in the order they are shown. */
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

        return MoneyFormatter::format($unitPrice, $product->currency) . ' ' . $shop?->unitPriceLabel();
    }
}
