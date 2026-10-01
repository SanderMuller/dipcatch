<?php declare(strict_types=1);

namespace App\Livewire\Shops;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\KeepShopAsLink;
use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\TrackedElsewhere;
use App\Billing\PlanLimitReached;
use App\Enums\ProbeFailure;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Support\Gtin;
use App\Support\PackSize;
use App\Support\UnitWord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
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

    /** Jev's chance that the previewed shop sells the same product (and pack, unless the preview is a Klarna lead suggestion); null when unchecked. */
    #[Locked]
    public ?float $sameProductChance = null;

    /**
     * The URL of a Klarna lead suggestion being added: its preview asks
     * whether the page sells the product in any pack size.
     */
    #[Locked]
    public ?string $anyPackUrl = null;

    public function mount(Product $product): void
    {
        Gate::authorize('view', $product);

        $this->product = $product;
        $this->manualCurrency = $product->currency !== '' ? $product->currency : 'EUR';
    }

    /**
     * A suggestion hands over a URL; the flow from here is the normal one. A
     * web suggestion also names its finding: the probe asks for the variant
     * its read picked, and a Klarna lead's preview asks the any-size question.
     */
    #[On('suggest-shop')]
    public function useSuggestion(string $url, ProbeShopUrl $probe, ?int $findingId = null): void
    {
        Gate::authorize('view', $this->product);

        $finding = $findingId === null ? null : WebShopFinding::query()->where('product_id', $this->product->id)->find($findingId);

        $url = $finding instanceof WebShopFinding ? ($finding->add_url ?? $finding->url) : $url;

        $this->resetProbeState();
        $this->url = $url;
        $this->anyPackUrl = $finding?->isLead() === true ? $url : null;

        $this->runProbe($probe, variantKey: $finding?->variant_key);

        // Whatever the probe answered, the suggestion's button stops saying
        // "Adding…"; the form now shows the preview or the error.
        $this->dispatch('shop-probe-finished');
    }

    protected function probeSubject(): Product
    {
        return $this->product;
    }

    protected function onPreviewShown(ProbeOutcome $outcome): void
    {
        $this->sameProductChance = app(ShopMatchCheck::class)->draft($this->product, $this->shopDraft(), anyPack: $this->anyPackUrl === $this->url);
    }

    /**
     * A pasted Klarna page on a product web discovery runs for: its shops are
     * looked up, and the refusal says so.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    protected function failureContext(ProbeOutcome $outcome, ?array $context): ?array
    {
        $klarnaUrl = $context['klarna_url'] ?? null;

        if ($context === null || $outcome->errorCode !== ProbeFailure::NotAShop || ! is_string($klarnaUrl) || ! app(WebShopDiscovery::class)->useKlarnaPage($this->product, $klarnaUrl)) {
            return $context;
        }

        return $context + ['looking_up' => true];
    }

    protected function onProbeReset(): void
    {
        $this->sameProductChance = null;
    }

    /** Whether Jev doubts the previewed shop sells this product. */
    public function doubtsSameProduct(): bool
    {
        return $this->sameProductChance !== null
            && $this->sameProductChance < Config::float('dipcatch.shop_checks.warn_below');
    }

    /**
     * A note when the previewed page sells another pack size than every shop
     * already tracked, in the same unit: a 300 g bar where the other shops
     * sell 100 g. Often a different product, not only a bigger pack, and it
     * needs no AI to notice. Per-kilo prices still compare, so it is a note,
     * not a refusal.
     */
    public function otherPackNote(): ?string
    {
        $size = $this->snapshotPackSize();
        $tracked = $this->trackedPackSizesInPageUnit();

        if (! $size instanceof PackSize || $tracked === [] || $this->sellsTrackedPack()) {
            return null;
        }

        return __('This page sells :pack. Your other shops sell :others. Check it is the same product, not another one in a bigger or smaller pack.', [
            'pack' => UnitWord::pack($size),
            'others' => implode(', ', array_map(UnitWord::pack(...), $tracked)),
        ]);
    }

    public function sellsTrackedPack(): bool
    {
        $size = $this->snapshotPackSize();

        return $size instanceof PackSize
            && array_any($this->trackedPackSizesInPageUnit(), fn (PackSize $other): bool => $other->isSameSizeAs($size));
    }

    public function barcodeMatchHost(): ?string
    {
        $gtin = Gtin::normalize($this->snapshot['gtin'] ?? null);

        if ($gtin === null) {
            return null;
        }

        $match = $this->product->shops->first(fn (Shop $shop): bool => Gtin::normalize($shop->gtin) === $gtin);

        return $match instanceof Shop ? $match->host : null;
    }

    /** A barcode match settles the check without the AI (chance 1.0), so its own badge says it instead. */
    public function sameProductPercent(): ?int
    {
        if ($this->sameProductChance === null || $this->doubtsSameProduct() || $this->barcodeMatchHost() !== null) {
            return null;
        }

        return (int) round($this->sameProductChance * 100);
    }

    /**
     * @return list<PackSize>
     */
    private function trackedPackSizesInPageUnit(): array
    {
        $size = $this->snapshotPackSize();

        if (! $size instanceof PackSize) {
            return [];
        }

        return $this->product->shops
            ->map(fn (Shop $shop): ?PackSize => $shop->packSize())
            ->filter(fn (?PackSize $other): bool => $other instanceof PackSize && $other->unit === $size->unit)
            ->unique(fn (PackSize $other): string => $other->quantity . $other->unit)
            ->values()
            ->all();
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
