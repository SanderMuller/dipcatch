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
class ProductList extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all, active or paused. Anything else reads as all. */
    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'created_at')]
    public string $sort = 'created_at';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    /** Only these are sortable; anything else arriving from the URL is ignored. */
    private const array SORTABLE = ['title', 'cheapest_price', 'created_at', 'shops_count'];

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, strict: true)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
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
        $sort = in_array($this->sort, self::SORTABLE, strict: true) ? $this->sort : 'created_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

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
            ->with(['cheapestShop', 'shops'])
            ->orderBy($sort, $direction)
            ->paginate();
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
