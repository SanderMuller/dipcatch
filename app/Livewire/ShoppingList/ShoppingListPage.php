<?php declare(strict_types=1);

namespace App\Livewire\ShoppingList;

use App\Models\Product;
use App\Models\User;
use App\Support\ShoppingList;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The account's one shopping list, grouped by the shop where each product is
 * the best buy now. Items are crossed off in the shop and stay struck through
 * until "Clear crossed off", so a mis-tap can be undone.
 */
#[Title('Shopping list')]
final class ShoppingListPage extends Component
{
    public function toggleCrossedOff(mixed $productId): void
    {
        $product = $this->ownProduct($productId);

        $product->setCrossedOff(crossedOff: ! $product->isCrossedOff());

        $this->dispatch('shopping-list-changed');
    }

    public function remove(mixed $productId): void
    {
        $product = $this->ownProduct($productId);
        $product->removeFromShoppingList();

        Flux::toast(text: __(':title is off the list.', ['title' => $product->title]));
        $this->dispatch('shopping-list-changed');
    }

    public function clearCrossedOff(): void
    {
        $cleared = Product::clearCrossedOffFor($this->user());

        Flux::toast(text: trans_choice('Cleared :count item.|Cleared :count items.', $cleared, ['count' => $cleared]));
        // Closed only once the clear went through; the trigger it came from is
        // gone, so focus goes to the heading.
        $this->js("\$flux.modal('clear-crossed-off').close(); document.getElementById('shopping-list-heading')?.focus()");
        $this->dispatch('shopping-list-changed');
    }

    public function render(): View
    {
        return view('livewire.shopping-list.shopping-list-page', [
            'list' => ShoppingList::forUser($this->user()),
        ]);
    }

    /**
     * One of this account's products. A value that is not a UUID answers 404
     * before any query: Postgres rejects it in a uuid comparison, which would
     * otherwise be a 500.
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
