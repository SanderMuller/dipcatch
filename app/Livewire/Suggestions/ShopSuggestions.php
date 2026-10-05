<?php declare(strict_types=1);

namespace App\Livewire\Suggestions;

use App\Actions\Shops\KeepShopAsLink;
use App\Actions\Suggestions\SuggestShops;
use App\Billing\PlanLimitReached;
use App\Enums\WebDiscoveryState;
use App\Livewire\Concerns\HidesShops;
use App\Models\Product;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use App\Services\Suggestions\ShopSuggestion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lists other shops that sell a tracked product: from the local checkjebon
 * dataset, and from web discovery (specs/web-shop-discovery.md). Accept hands
 * the URL to `AddShop`, which probes it again; no suggested price is stored.
 */
final class ShopSuggestions extends Component
{
    use HidesShops;

    public string $productId;

    /**
     * Whether the list starts open. Closed by default so the panel does not
     * push the tracked shops off the first screen; the add-shop form opens it
     * after an add, when the remaining suggestions are the reason to look.
     */
    public bool $expanded = false;

    /**
     * Whether an empty list says so. Only the copy inside the add-shop form
     * does: someone who opened it is looking for a shop, while under the
     * section heading the line reads like a finding about every shop.
     */
    #[Locked]
    public bool $explainEmpty = false;

    /** When the panel mounted, so polling for web suggestions stops in time. */
    #[Locked]
    public int $mountedAt = 0;

    public function mount(Product $product, bool $expanded = false, bool $explainEmpty = false): void
    {
        Gate::authorize('view', $product);

        $this->productId = (string) $product->id;
        $this->expanded = $expanded;
        $this->explainEmpty = $explainEmpty;
        $this->mountedAt = now()->getTimestamp();
    }

    /**
     * `$findingId` names the web suggestion behind the URL, so the add-shop
     * form tracks the variant its read picked and, for a Klarna lead, asks
     * the any-size question.
     */
    public function accept(string $url, ?int $findingId = null): void
    {
        $this->product();

        $this->dispatch('suggest-shop', url: $url, findingId: $findingId)->to('shops.add-shop');

        // The add-shop form lives in a collapsed disclosure on the product
        // page. Without this the probe preview would render inside a closed
        // <details> and the click would look like it did nothing.
        $this->dispatch('open-add-shop');
    }

    /**
     * Keeps a suggestion DipCatch cannot price as a link on the product. The
     * row is looked up again here, so only a listed, untrackable suggestion
     * can be kept, and a second click finds nothing once the shop is added.
     */
    public function keepAsLink(string $chain, string $externalId, SuggestShops $suggest, KeepShopAsLink $keep): void
    {
        $product = $this->product();
        $suggestion = array_find(
            $suggest($product, verify: false),
            static fn (ShopSuggestion $suggestion): bool => $suggestion->chain === $chain && $suggestion->externalId === $externalId && ! $suggestion->trackable,
        );

        if (! $suggestion instanceof ShopSuggestion) {
            return;
        }

        try {
            $shop = $keep($product, $suggestion->url);
        } catch (PlanLimitReached $e) {
            Flux::toast(text: $e->getMessage(), heading: __('You have used all your shops on this product'), variant: 'warning');

            return;
        }

        $suggest->forgetSuggestions();

        Flux::toast(
            text: __('DipCatch cannot read :shop yet, so it holds no price and never decides the cheapest or the best value. It is checked again once a week, and starts being tracked by itself if the page becomes readable.', ['shop' => $suggestion->chainLabel]),
            heading: __('Kept as a link'),
            variant: 'success',
        );

        $this->dispatch('shop-added', offerId: (string) $shop->id);
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
     * Hides one web suggestion, on this product only: a finding id of another
     * product answers 404.
     */
    public function dismissWeb(int $findingId): void
    {
        WebShopFinding::query()
            ->where('product_id', $this->product()->id)
            ->findOrFail($findingId)
            ->update(['dismissed_at' => now()]);

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
    public function refreshSuggestions(): void
    {
        // A new shop can start the web suggestions over, so the panel polls
        // again for the full window rather than from when it first opened.
        $this->mountedAt = now()->getTimestamp();
    }

    public function render(SuggestShops $suggest): View
    {
        $product = $this->product();
        $webShown = $product->user instanceof User && $product->user->wantsShopChecks();
        $discovery = $webShown ? WebDiscovery::query()->find($product->id) : null;
        $discovering = $webShown && self::discovering($product, $discovery);
        $pollSeconds = $discovering ? $this->pollSeconds() : null;
        $searchingFor = $discovering ? $this->searchingFor($discovery) : 0;

        return view('livewire.suggestions.shop-suggestions', [
            'product' => $product,
            'suggestions' => $suggest($product),
            'webSuggestions' => $webShown ? WebShopFinding::shownFor($product) : new EloquentCollection(),
            'discovering' => $discovering,
            'searchingFor' => $searchingFor,
            // The bar shows while the panel polls. Once it stops, the plain
            // line stays: a bar that no longer moves claims more than anyone knows.
            'showsProgress' => $pollSeconds !== null,
            'pollSeconds' => $pollSeconds,
            // Distinguish "nothing matched" from "nothing to match against":
            // an empty or stale catalogue is an operational problem, not an
            // answer, so the panel stays silent rather than claiming no shop
            // sells this product.
            'datasetIsUsable' => $suggest->hasUsableCatalogue(),
        ]);
    }

    /**
     * A queued job cannot send the browser an event, so an open panel polls
     * while discovery runs, for a bounded time.
     */
    private function pollSeconds(): ?int
    {
        $elapsed = now()->getTimestamp() - $this->mountedAt;

        return match (true) {
            $elapsed < Config::integer('dipcatch.web_discovery.poll_fast_for_seconds') => Config::integer('dipcatch.web_discovery.poll_fast_seconds'),
            $elapsed < Config::integer('dipcatch.web_discovery.poll_for_seconds') => Config::integer('dipcatch.web_discovery.poll_seconds'),
            default => null,
        };
    }

    /**
     * Seconds since the search was queued, so the progress bar picks up
     * where it was after a reload rather than starting over. A search queued
     * longer ago than the polling window, a night's re-check say, is timed
     * from when the panel opened: a bar near its end would claim it is
     * almost done.
     */
    private function searchingFor(?WebDiscovery $discovery): int
    {
        $queuedAt = $discovery?->queued_at;
        $sinceQueued = $queuedAt === null ? 0 : max(0, (int) $queuedAt->diffInSeconds(now()));

        return $sinceQueued < Config::integer('dipcatch.web_discovery.poll_for_seconds')
            ? $sinceQueued
            : now()->getTimestamp() - $this->mountedAt;
    }

    private static function discovering(Product $product, ?WebDiscovery $discovery): bool
    {
        $state = $discovery?->state;

        if ($state === WebDiscoveryState::Queued || $state === WebDiscoveryState::Running) {
            return true;
        }

        return WebShopFinding::query()->where('product_id', $product->id)->current($product)->whereNull('dismissed_at')->unfinished()->exists();
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
