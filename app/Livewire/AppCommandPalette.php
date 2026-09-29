<?php declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

/**
 * The search in the header. Pages are filtered in the browser; products are
 * searched here, across the whole account, so a product added long ago is as
 * easy to find as a new one.
 */
final class AppCommandPalette extends Component
{
    private const int LIMIT = 8;

    public string $search = '';

    public function render(): View
    {
        return view('livewire.app-command-palette', [
            'products' => $this->products(),
        ]);
    }

    /**
     * The newest products while nothing is typed; otherwise the ones whose
     * title, shop or category contains what was typed.
     *
     * @return EloquentCollection<int, Product>
     */
    private function products(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection();
        }

        $term = mb_strtolower(trim($this->search));
        $query = Product::query()->where('user_id', $user->id);

        if ($term === '') {
            return $query->latest()->limit(self::LIMIT)->get();
        }

        // `%` and `_` are wildcards to LIKE; typed, they mean themselves.
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $categories = array_values(array_map(
            fn (ProductCategory $category): string => $category->value,
            array_filter(ProductCategory::cases(), fn (ProductCategory $category): bool => str_contains(mb_strtolower($category->label()), $term)),
        ));

        return $query
            ->where(fn (EloquentQueryBuilder $match): EloquentQueryBuilder => $match
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('shops', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop->whereRaw("LOWER(host) LIKE ? ESCAPE '\\'", [$like]))
                ->when($categories !== [], fn (EloquentQueryBuilder $category): EloquentQueryBuilder => $category->orWhereIn('category', $categories)))
            ->orderByRaw('LOWER(title)')
            ->limit(self::LIMIT)
            ->get();
    }
}
