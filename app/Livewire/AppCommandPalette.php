<?php declare(strict_types=1);

namespace App\Livewire;

use App\Billing\BillingGate;
use App\Enums\ProductCategory;
use App\Models\EmptySearch;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\FilterSuggestions;
use App\Support\HeadlinePrice;
use App\Support\ShopNames;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The search in the header. Pages are filtered in the browser; products,
 * shops and categories are searched here, across the whole account, so a
 * product added long ago is as easy to find as a new one.
 */
final class AppCommandPalette extends Component
{
    private const int LIMIT = 8;

    private const int SHOP_LIMIT = 3;

    public string $search = '';

    /**
     * Records a search the palette showed nothing for. The browser calls it
     * once the list stays empty; the product match is checked again here, so
     * a reply that was still on its way is not counted as nothing found.
     * Async, so the next keystroke does not wait for it.
     */
    #[Async]
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
            // Only for someone who can buy it now.
            'showUpgrade' => $user instanceof User && ! $user->isPro() && BillingGate::isOpen(),
        ]);
    }

    /**
     * Read by the view's results island, which a keystroke re-renders on its
     * own: the server does not render the pages again.
     *
     * @return array{products: Collection<int, array{product: Product, headline: HeadlinePrice}>, shops: list<array{host: string, name: string|null}>, categories: list<array{kind: 'shop'|'category', key: string, label: string}>}
     */
    #[Computed]
    public function results(): array
    {
        return [
            'products' => $this->products($this->search)->toBase()->map(static fn (Product $product): array => [
                'product' => $product,
                'headline' => HeadlinePrice::of($product),
            ]),
            'shops' => $this->shops($this->search),
            'categories' => $this->categories($this->search),
        ];
    }

    /**
     * The newest products while the search is shorter than the minimum;
     * otherwise the ones whose title, shop or category contains what was typed.
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
        return (mb_strlen($term) < FilterSuggestions::MIN_SEARCH_LENGTH ? Product::query()->where('user_id', $user->id)->latest() : $this->matching($user, $term)->orderByRaw('LOWER(title)'))
            ->with(['shops', 'cheapestShop'])
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * The active shops this account follows a product at, matched on host or
     * name, as the products page lists them.
     *
     * @return list<array{host: string, name: string|null}>
     */
    private function shops(string $search): array
    {
        $user = auth()->user();
        $term = mb_strtolower(trim($search));

        if (! $user instanceof User || mb_strlen($term) < FilterSuggestions::MIN_SEARCH_LENGTH) {
            return [];
        }

        $hosts = Shop::query()
            ->where('active', true)
            ->whereHas('product', fn (EloquentQueryBuilder $product): EloquentQueryBuilder => $product->where('user_id', $user->id))
            ->tap(fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $this->whereHostOrNameMatches($shop, $term))
            ->distinct()
            ->orderBy('host')
            ->limit(self::SHOP_LIMIT)
            ->pluck('host')
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '')
            ->values()
            ->all();

        $names = ShopNames::all();

        return array_map(fn (string $host): array => ['host' => $host, 'name' => $names[$host] ?? null], $hosts);
    }

    /**
     * The departments and categories this account has products in whose
     * name contains what was typed, chosen as the products page chooses its
     * suggestions.
     *
     * @return list<array{kind: 'shop'|'category', key: string, label: string}>
     */
    private function categories(string $search): array
    {
        $user = auth()->user();

        if (! $user instanceof User || mb_strlen(trim($search)) < FilterSuggestions::MIN_SEARCH_LENGTH) {
            return [];
        }

        /** @var Collection<int, ProductCategory> $used the column is cast, so pluck() yields enums */
        $used = Product::query()->where('user_id', $user->id)->whereNotNull('category')->distinct()->pluck('category');

        $groups = array_map(
            static fn (array $categories): array => array_values(array_filter($categories, static fn (ProductCategory $category): bool => $used->contains($category))),
            ProductCategory::grouped(),
        );

        return FilterSuggestions::for($search, [], $groups, except: []);
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private function matching(User $user, string $term): EloquentQueryBuilder
    {
        $like = self::like($term);
        $categories = array_values(array_map(
            fn (ProductCategory $category): string => $category->value,
            array_filter(ProductCategory::cases(), fn (ProductCategory $category): bool => str_contains(mb_strtolower($category->label()), $term)),
        ));

        return Product::query()
            ->where('user_id', $user->id)
            ->where(fn (EloquentQueryBuilder $match): EloquentQueryBuilder => $match
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('shops', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $this->whereHostOrNameMatches($shop, $term))
                ->when($categories !== [], fn (EloquentQueryBuilder $category): EloquentQueryBuilder => $category->orWhereIn('category', $categories)));
    }

    /**
     * @param  EloquentQueryBuilder<Shop>  $shop
     * @return EloquentQueryBuilder<Shop>
     */
    private function whereHostOrNameMatches(EloquentQueryBuilder $shop, string $term): EloquentQueryBuilder
    {
        $namedHosts = ShopNames::hostsMatching($term);

        return $shop->where(fn (EloquentQueryBuilder $match): EloquentQueryBuilder => $match
            ->whereRaw("LOWER(host) LIKE ? ESCAPE '\\'", [self::like($term)])
            ->when($namedHosts !== [], fn (EloquentQueryBuilder $named): EloquentQueryBuilder => $named->orWhereIn('host', $namedHosts)));
    }

    /**
     * `%` and `_` are wildcards to LIKE; typed, they mean themselves.
     */
    private static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
