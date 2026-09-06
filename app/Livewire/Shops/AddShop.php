<?php declare(strict_types=1);

namespace App\Livewire\Shops;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ProbeShopUrl;
use App\Billing\PlanLimitReached;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\Product;
use Filament\Notifications\Notification;
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

        $draft = $this->shopDraft();

        try {
            $offerId = (string) app(AttachShop::class)($this->product, $draft)->id;
        } catch (PlanLimitReached $e) {
            Notification::make()
                ->warning()
                ->title('Plan limit reached')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

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
