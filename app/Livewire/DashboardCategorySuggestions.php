<?php declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Products\CategoriseProduct;
use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * A few products without a category on the dashboard, to file in one click.
 * Products with an AI suggestion come first. A product the person left
 * without a category on purpose (`category_set_by` user) is not asked about.
 */
final class DashboardCategorySuggestions extends Component
{
    private const int SHOWN = 3;

    /** @var array<string, string> Why "Suggest a category" found nothing, by product id. */
    public array $suggestionMessages = [];

    public function useSuggestion(string $productId): void
    {
        $product = $this->ownProduct($productId);

        if ($product->category !== null || $product->suggested_category === null) {
            return;
        }

        $this->file($product, $product->suggested_category);
    }

    /** Pro asks for a suggestion on the spot, as the edit form's button does. */
    public function suggestCategory(string $productId): void
    {
        $product = $this->ownProduct($productId);
        unset($this->suggestionMessages[$productId]);

        if (! $this->canSuggest() || $product->category !== null || $product->suggested_category !== null) {
            return;
        }

        $message = CategoriseProduct::suggest($product);

        if ($message !== null) {
            $this->suggestionMessages[$productId] = $message;
        }
    }

    public function choose(string $productId, string $category): void
    {
        $chosen = ProductCategory::tryFrom($category);

        if ($chosen === null) {
            return;
        }

        $this->file($this->ownProduct($productId), $chosen);
    }

    /** As "No category" on the edit form: a choice, so the product is not asked about again. */
    public function leaveUncategorised(string $productId): void
    {
        $product = $this->ownProduct($productId);

        if ($product->category !== null) {
            return;
        }

        CategoriseProduct::chooseByUser($product, category: null);
        $product->save();
    }

    public function render(): View
    {
        $ready = $this->uncategorised()->reject(fn (Product $product): bool => CategoriseProduct::isPending($product));

        return view('livewire.dashboard-category-suggestions', [
            'products' => $ready->take(self::SHOWN),
            'hasMore' => $ready->count() > self::SHOWN,
            'categoryGroups' => ProductCategory::grouped(),
            'noCategoryFilter' => ProductList::NO_CATEGORY,
            'canSuggest' => $this->canSuggest(),
        ]);
    }

    private function canSuggest(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return TypeSafeClient::configured() && $user->entitlements()->allowsAutoCategories();
    }

    private function file(Product $product, ProductCategory $category): void
    {
        CategoriseProduct::chooseByUser($product, $category);
        $product->save();

        Flux::toast(__(':product is now under :category.', ['product' => $product->title, 'category' => $category->label()]));
    }

    private function ownProduct(string $productId): Product
    {
        abort_unless(Str::isUuid($productId), 404);
        $product = Product::query()->findOrFail($productId);
        $this->authorize('update', $product);

        return $product;
    }

    /**
     * One more than are shown, and a few spare for the ones still being
     * categorised, so the list still fills and can tell there are more.
     *
     * @return EloquentCollection<int, Product>
     */
    private function uncategorised(): EloquentCollection
    {
        return Product::query()
            ->where('user_id', auth()->id())
            ->whereNull('category')
            ->whereNull('category_set_by')
            ->orderByRaw('suggested_category IS NULL')
            ->latest('created_at')
            ->limit(self::SHOWN + 3)
            ->get();
    }
}
