<?php declare(strict_types=1);

namespace App\Livewire\ShoppingList;

use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\HeadlinePrice;
use App\Support\ShoppingList;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The list icon beside the bell: how much is left to buy, and the first few
 * items. It renders on every app page, so it reads a fixed four queries
 * whatever the list's size, and never the whole grouped list.
 */
final class HeaderMenu extends Component
{
    /** How many open items the dropdown shows. The rest live on the list page. */
    private const int LIMIT = 8;

    /**
     * Items crossed off from this menu. They stay in it, struck through, so a
     * mis-tap can be undone where it happened; the next page starts without.
     *
     * @var list<string>
     */
    public array $crossedHere = [];

    /**
     * Another tab and a price check change the list too; the poll in the
     * view catches those. This catches the changes made on this page.
     */
    #[On('shopping-list-changed')]
    public function refreshList(): void {}

    public function toggleCrossedOff(mixed $productId): void
    {
        $product = $this->ownProduct($productId);
        $crossOff = ! $product->isCrossedOff();

        $product->setCrossedOff($crossOff);

        $id = (string) $product->id;
        $this->crossedHere = $crossOff
            ? array_values(array_unique([...$this->crossedHere, $id]))
            : array_values(array_diff($this->crossedHere, [$id]));

        $this->announceChange();
    }

    public function remove(mixed $productId): void
    {
        $product = $this->ownProduct($productId);
        $product->removeFromShoppingList();

        Flux::toast(text: __(':title is off the list.', ['title' => $product->title]));
        $this->announceChange();
    }

    /**
     * To the pages that show the list, not to this component: a listener
     * here would render the menu a second time for its own change.
     */
    private function announceChange(): void
    {
        foreach ([ShoppingListPage::class, ProductList::class, ProductShow::class] as $page) {
            $this->dispatch('shopping-list-changed')->to($page);
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $counts = $this->counts($user);

        return view('livewire.shopping-list.header-menu', [
            'listedCount' => $counts['listed'],
            'openCount' => $counts['open'],
            'items' => $counts['open'] === 0 && $this->crossedHere === [] ? [] : $this->items($user),
        ]);
    }

    /**
     * Both counts in one query, so the menu can tell an empty list from one
     * where everything is crossed off.
     *
     * @return array{listed: int, open: int}
     */
    private function counts(User $user): array
    {
        $row = Product::query()
            ->where('user_id', $user->id)
            ->onShoppingList()
            ->toBase()
            ->selectRaw('count(*) as listed_count')
            ->selectRaw('count(list_checked_at) as checked_count')
            ->first();

        $listed = self::whole($row->listed_count ?? null);
        $checked = self::whole($row->checked_count ?? null);

        return [
            'listed' => $listed,
            'open' => $listed - $checked,
        ];
    }

    private static function whole(mixed $count): int
    {
        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<array{product: Product, headline: HeadlinePrice, shop: ?Shop, skippedBest: ?Shop, crossedOff: bool}>
     */
    private function items(User $user): array
    {
        $products = Product::query()
            ->where('user_id', $user->id)
            ->onShoppingList()
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query
                ->whereNull('list_checked_at')
                ->orWhereIn('id', array_filter($this->crossedHere, Str::isUuid(...))))
            ->with(['cheapestShop', 'shops'])
            ->orderBy('listed_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return array_values($products->map(fn (Product $product): array => ShoppingList::item($product))->all());
    }

    /**
     * Only this account's products: a value that is not a UUID answers 404
     * before any query, as Postgres would reject it in a uuid comparison.
     */
    private function ownProduct(mixed $productId): Product
    {
        abort_unless(is_string($productId) && Str::isUuid($productId), 404);

        return $this->user()->products()->findOrFail($productId);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
