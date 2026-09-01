<?php declare(strict_types=1);

namespace App\Livewire\Suggestions;

use App\Actions\Suggestions\SuggestShops;
use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lists the other shops that sell a tracked product, matched against the
 * local checkjebon dataset. Accepting one hands its URL to `AddShop`, which
 * probes it and shows the normal preview - the dataset price is never stored.
 */
class ShopSuggestions extends Component
{
    public string $productId;

    public function mount(Product $product): void
    {
        Gate::authorize('view', $product);

        $this->productId = (string) $product->id;
    }

    public function accept(string $url): void
    {
        $this->product();

        $this->dispatch('suggest-shop', url: $url)->to('shops.add-shop');

        // The add-shop form lives in a collapsed disclosure on the product
        // page. Without this the probe preview would render inside a closed
        // <details> and the click would look like it did nothing.
        $this->dispatch('open-add-shop');
    }

    public function dismiss(string $chain, string $externalId, SuggestShops $suggest): void
    {
        $suggest->dismiss($this->product(), $chain, $externalId);

        // The page renders this component twice (the panel and the copy
        // inside the add-shop form). Without this the hidden one keeps the
        // dismissed row in its DOM and shows it again when it reappears.
        $this->dispatch('shop-suggestions-changed');
    }

    /**
     * Adding or removing a shop changes which chains are already tracked, so
     * the list is stale until the component re-renders. The listener needs no
     * body: `render()` recomputes the suggestions.
     */
    #[On('shop-added')]
    #[On('shop-removed')]
    #[On('shop-suggestions-changed')]
    public function refreshSuggestions(): void {}

    public function render(SuggestShops $suggest): View
    {
        return view('livewire.suggestions.shop-suggestions', [
            'suggestions' => $suggest($this->product()),
            // Distinguish "nothing matched" from "nothing to match against":
            // an empty or stale catalogue is an operational problem, not an
            // answer, so the panel stays silent rather than claiming no shop
            // sells this product.
            'datasetIsUsable' => $suggest->hasUsableCatalogue(),
        ]);
    }

    /**
     * Re-resolve and re-authorize on every request. Livewire re-hydrates
     * public state per call, so a `mount()`-only check is bypassable by
     * tampering with the id.
     */
    private function product(): Product
    {
        $product = Product::query()->findOrFail($this->productId);

        Gate::authorize('view', $product);

        return $product;
    }
}
