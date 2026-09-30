<?php declare(strict_types=1);

namespace App\Livewire;

use App\Billing\BillingGate;
use App\Enums\ProductCategory;
use App\Models\EmptySearch;
use App\Models\Product;
use App\Models\User;
use App\Support\HeadlinePrice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
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

    /**
     * Records a search the palette showed nothing for. The browser calls it
     * once the list stays empty; the product match is checked again here, so
     * a reply that was still on its way is not counted as nothing found.
     */
    #[Renderless]
    public function logEmptySearch(string $term): void
    {
        $user = auth()->user();
        $term = EmptySearch::normalize($term);

        if (! $user instanceof User || $term === null) {
            return;
        }

        RateLimiter::attempt("empty-search:{$user->id}", 30, function () use ($user, $term): void {
            if (! $this->matching($user, $term)->exists()) {
                EmptySearch::record($user, $term);
            }
        });
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.app-command-palette', [
            'products' => $this->products($this->search)->map(static fn (Product $product): array => [
                'product' => $product,
                'headline' => HeadlinePrice::of($product),
            ]),
            // Only for someone who can buy it now.
            'showUpgrade' => $user instanceof User && ! $user->isPro() && BillingGate::isOpen(),
        ]);
    }

    /**
     * The newest products while nothing is typed; otherwise the ones whose
     * title, shop or category contains what was typed.
     *
     * @return EloquentCollection<int, Product>
     */
    private function products(string $search): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection();
        }

        $term = mb_strtolower(trim($search));

        // The rows show the best buy, as the shopping list does.
        return ($term === '' ? Product::query()->where('user_id', $user->id)->latest() : $this->matching($user, $term)->orderByRaw('LOWER(title)'))
            ->with(['shops', 'cheapestShop'])
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private function matching(User $user, string $term): EloquentQueryBuilder
    {
        // `%` and `_` are wildcards to LIKE; typed, they mean themselves.
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $categories = array_values(array_map(
            fn (ProductCategory $category): string => $category->value,
            array_filter(ProductCategory::cases(), fn (ProductCategory $category): bool => str_contains(mb_strtolower($category->label()), $term)),
        ));

        return Product::query()
            ->where('user_id', $user->id)
            ->where(fn (EloquentQueryBuilder $match): EloquentQueryBuilder => $match
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('shops', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop->whereRaw("LOWER(host) LIKE ? ESCAPE '\\'", [$like]))
                ->when($categories !== [], fn (EloquentQueryBuilder $category): EloquentQueryBuilder => $category->orWhereIn('category', $categories)));
    }
}
