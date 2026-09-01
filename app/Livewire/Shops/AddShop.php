<?php declare(strict_types=1);

namespace App\Livewire\Shops;

use App\Actions\Shops\ProbeShopUrl;
use App\Enums\ScrapeStatus;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\PriceCheck;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Add-shop form for an existing product. The probe state machine lives
 * in DrivesShopProbe; this component owns persistence on Confirm.
 */
class AddShop extends Component
{
    use DrivesShopProbe;

    public Product $product;

    public function mount(Product $product): void
    {
        Gate::authorize('view', $product);

        $this->product = $product;
        $this->manualCurrency = $product->currency !== '' ? $product->currency : 'EUR';
    }

    /** A suggestion hands over a URL; the flow from here is the normal one. */
    #[On('suggest-shop')]
    public function useSuggestion(string $url, ProbeShopUrl $probe): void
    {
        Gate::authorize('view', $this->product);

        $this->resetProbeState();
        $this->url = $url;

        $this->runProbe($probe);
    }

    /**
     * Called by `runProbe()` on every probe path — `probe()`,
     * `probeWithSelectors()` and `selectVariant()` alike. Livewire
     * re-hydrates `$product` from the request on each call without
     * authorizing it, so the ownership check belongs here, not in mount().
     */
    protected function probeSubject(): ?Product
    {
        Gate::authorize('view', $this->product);

        return $this->product;
    }

    public function confirm(): void
    {
        Gate::authorize('view', $this->product);

        if ($this->state !== 'preview' || $this->snapshot === null
            || $this->normalizedUrl === null || $this->host === null
            || $this->adapterKey === null) {
            return;
        }

        $rawPrice = $this->snapshot['price'] ?? '';
        $rawCurrency = $this->snapshot['currency'] ?? '';
        $snapshotPrice = is_string($rawPrice) ? $rawPrice : '';
        $snapshotCurrency = is_string($rawCurrency) ? $rawCurrency : '';
        $snapshotInStock = (bool) ($this->snapshot['in_stock'] ?? true);

        $usedManualSelector = $this->adapterKey === 'user-selector';
        $priceSelector = $usedManualSelector ? trim($this->priceSelector) : null;
        $titleSelector = $usedManualSelector ? (trim($this->titleSelector) ?: null) : null;
        $imageSelector = $usedManualSelector ? (trim($this->imageSelector) ?: null) : null;
        $imageUrl = $this->snapshotImageUrl();
        $gtin = $this->snapshotGtin();
        $variantKey = $this->chosenVariantKey;
        $packSize = $this->snapshotPackSize();

        $offerId = DB::transaction(function () use (
            $snapshotPrice,
            $snapshotCurrency,
            $snapshotInStock,
            $priceSelector,
            $titleSelector,
            $imageSelector,
            $imageUrl,
            $gtin,
            $variantKey,
            $packSize,
        ): string {
            $shop = $this->product->shops()->create([
                'url' => $this->normalizedUrl,
                'adapter_key' => $this->adapterKey,
                'price_selector' => $priceSelector,
                'title_selector' => $titleSelector,
                'image_selector' => $imageSelector,
                'image_url' => $imageUrl,
                'gtin' => $gtin,
                'variant_key' => $variantKey,
                'pack_quantity' => $packSize?->quantity,
                'pack_unit' => $packSize?->unit,
                'currency' => $snapshotCurrency,
                'initial_price' => $snapshotPrice,
                'initial_checked_at' => now(),
                'current_price' => $snapshotPrice,
                'current_in_stock' => $snapshotInStock,
                'last_checked_at' => now(),
                'last_success_at' => now(),
                'last_status' => ScrapeStatus::Ok->value,
            ]);

            $check = PriceCheck::create([
                'shop_id' => $shop->id,
                'price' => $snapshotPrice,
                'currency' => $snapshotCurrency,
                'in_stock' => $snapshotInStock,
                'status' => ScrapeStatus::Ok->value,
                'checked_at' => now(),
            ]);

            $this->product->recomputeCheapestShop((int) $check->id);

            return (string) $shop->id;
        });

        $this->dispatch('shop-added', offerId: $offerId);
        $this->resetProbeState();
    }

    public function cancel(): void
    {
        Gate::authorize('view', $this->product);

        $this->resetProbeState();
    }

    public function render(): View
    {
        return view('livewire.shops.add-shop');
    }
}
