<?php declare(strict_types=1);

namespace App\Livewire\Shops;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\KeepShopAsLink;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\TrackedElsewhere;
use App\Billing\PlanLimitReached;
use App\Enums\ProbeFailure;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\Product;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Add-shop form for an existing product. The probe state machine lives
 * in DrivesShopProbe; this component owns persistence on Confirm.
 */
final class AddShop extends Component
{
    use DrivesShopProbe;

    public Product $product;

    /** Keep suggestions visible whenever the add-shop form is open. */
    public bool $expandSuggestions = true;

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

    protected function probeSubject(): Product
    {
        return $this->product;
    }

    /**
     * The user's other products already on this page, for the preview.
     *
     * A note, not a refusal: the same URL on two products is legitimate when
     * each tracks its own variant, and the person adding it is the one who
     * knows which case this is.
     */
    public function alreadyTrackedNote(): ?string
    {
        return TrackedElsewhere::note(TrackedElsewhere::productTitles(
            $this->product->user_id,
            $this->normalizedUrl,
            $this->chosenVariantKey,
            excludeProductId: $this->product->getKey(),
        ));
    }

    /**
     * Runs on every probe path — `probe()`, `probeWithSelectors()`,
     * `selectVariant()` and `showManualSelector()` alike. Livewire re-hydrates
     * `$product` from the request on each call without authorizing it, so the
     * ownership check belongs here, not in mount().
     */
    protected function authorizeProbeSubject(): void
    {
        Gate::authorize('view', $this->product);
    }

    protected function defaultManualCurrency(): string
    {
        return $this->product->currency !== '' ? $this->product->currency : 'EUR';
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
                ->title('You have used all your free products')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->dispatch('shop-added', offerId: $offerId);
        $this->resetProbeState();
    }

    /**
     * Whether the wall this URL hit is one worth keeping the link behind.
     *
     * Read from the failure rather than offered on every error: a rate limit
     * clears in seconds, and offering a permanent second-class row there would
     * turn "wait a moment" into a decision.
     */
    public function canKeepAsLink(): bool
    {
        return $this->state === 'error'
            && $this->url !== ''
            && ProbeFailure::tryFrom((string) $this->errorCode)?->isWorthKeepingAsLink() === true;
    }

    /**
     * Keep the page as a link: no price, out of both answers, retried weekly.
     *
     * Finding a shop that sells the thing is the slow part, and this is the
     * moment that work would otherwise be thrown away.
     */
    public function keepAsLink(KeepShopAsLink $keep): void
    {
        Gate::authorize('view', $this->product);

        if (! $this->canKeepAsLink()) {
            return;
        }

        try {
            $shop = $keep($this->product, $this->url, (string) $this->errorCode);
        } catch (InvalidArgumentException) {
            $this->failWith(ProbeFailure::InvalidUrl->value, context: null);

            return;
        } catch (PlanLimitReached $e) {
            Notification::make()
                ->warning()
                ->title('You have used all your shops on this product')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Kept as a link')
            ->body('DipCatch cannot read ' . $shop->host . ', so it holds no price and never decides the cheapest or the best value. It is checked again once a week, and starts being tracked by itself if the page becomes readable.')
            ->send();

        $this->dispatch('shop-added', offerId: (string) $shop->id);
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
