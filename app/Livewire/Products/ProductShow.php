<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\HistoryWindow;
use App\Billing\Plan;
use App\Billing\PlanLimits;
use App\Charts\PriceHistorySeries;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One product: its price, the shops it is tracked at, and the history chart.
 *
 * The chart's rows come from the server so the plan window is decided in
 * one place — `$range` arrives from the client, and
 * `HistoryWindow::start()` clamps it whatever the menu offered.
 */
class ProductShow extends Component
{
    public Product $product;

    #[Url(as: 'range', except: '90')]
    public string $range = '90';

    public ?string $shopMessage = null;

    /** The shop whose panel is open, and the fields it is editing. */
    public ?string $editingShopId = null;

    public string $editingUrl = '';

    public string $editingNotes = '';

    public ?string $shareMessage = null;

    public function mount(Product $product): void
    {
        // Ownership is checked here, not left to a scoped query: this component
        // is reached by route-model binding on an id from the URL.
        $this->authorize('view', $product);

        $this->product = $product;
    }

    /**
     * Load one shop into the edit panel.
     *
     * The values ride on the component rather than being written into the
     * markup: a URL or a note interpolated into an Alpine attribute breaks
     * the page's JavaScript the moment it contains a quote.
     */
    public function editShop(string $shopId): void
    {
        $shop = $this->shop($shopId);

        $this->authorize('update', $shop);

        $this->editingShopId = $shop->id;
        $this->editingUrl = $shop->url;
        $this->editingNotes = $shop->notes ?? '';
        $this->shopMessage = null;
    }

    public function saveEditedUrl(): void
    {
        if ($this->editingShopId !== null) {
            $this->saveShopUrl($this->editingShopId, $this->editingUrl);
        }
    }

    public function saveEditedNotes(): void
    {
        if ($this->editingShopId !== null) {
            $this->saveShopNotes($this->editingShopId, $this->editingNotes);
        }
    }

    /**
     * Public sharing: create, replace or withdraw the link.
     *
     * Every write is an atomic conditional UPDATE against the slug this
     * component last saw, not a read-then-write. Two tabs on the same product
     * would otherwise overwrite each other, and the loser would hand out a URL
     * that had already stopped working.
     */
    public function generateShareLink(): void
    {
        $this->authorize('update', $this->product);

        $updated = Product::query()
            ->whereKey($this->product->getKey())
            ->whereNull('share_slug')
            ->update(['share_slug' => Str::random(32)]);

        $this->product->refresh();

        $this->shareMessage = $updated === 0
            ? 'This product was already shared in another tab.'
            : 'Public link created.';
    }

    public function rotateShareLink(): void
    {
        $this->authorize('update', $this->product);

        // Conditional on the slug we saw rather than merely on "still shared":
        // a stop-then-share elsewhere has already issued a new URL, and
        // rotating would silently revoke it.
        $updated = Product::query()
            ->whereKey($this->product->getKey())
            ->where('share_slug', $this->product->share_slug)
            ->update(['share_slug' => Str::random(32)]);

        $this->product->refresh();

        $this->shareMessage = $updated === 0
            ? 'The link changed in another tab, so nothing was rotated.'
            : 'Public link replaced. The old one stops working now.';
    }

    public function stopSharing(): void
    {
        $this->authorize('update', $this->product);

        $updated = Product::query()
            ->whereKey($this->product->getKey())
            ->where('share_slug', $this->product->share_slug)
            ->update(['share_slug' => null]);

        $this->product->refresh();

        $this->shareMessage = $updated === 0
            ? 'The link changed in another tab, so nothing was withdrawn.'
            : 'Public sharing stopped. The link now returns a 404.';
    }

    public function togglePaused(): void
    {
        $this->authorize('update', $this->product);

        $this->product->forceFill(['active' => ! $this->product->active])->save();
        $this->product->refresh();
    }

    /**
     * Repair a shop's URL when the product moved. The re-check runs
     * synchronously because the person is waiting on the new price, and a
     * sync dispatch also bypasses ShouldBeUnique — a background recheck
     * already holding the per-offer lock would otherwise swallow this run.
     */
    public function saveShopUrl(string $shopId, string $url): void
    {
        $shop = $this->shop($shopId);

        $this->authorize('update', $shop);

        try {
            $normalized = UrlNormalizer::normalize(trim($url));
        } catch (InvalidArgumentException) {
            $this->shopMessage = 'That URL is not valid';

            return;
        }

        if (UrlNormalizer::hash($normalized) === $shop->url_hash) {
            $this->shopMessage = 'That URL is already saved. Nothing to update.';

            return;
        }

        $collision = Shop::query()
            ->where('product_id', $shop->product_id)
            ->where('url_hash', UrlNormalizer::hash($normalized))
            ->whereKeyNot($shop->id)
            ->exists();

        if ($collision) {
            $this->shopMessage = 'Another shop for this product already uses that URL';

            return;
        }

        $shop->updateUrl($normalized);

        dispatch_sync(new CheckShopPrice($shop->refresh()));

        // The check recomputes the cheapest offer itself, except when it gives
        // up early on a rate-limited host. The offer has no price from here on,
        // so without this the product would keep advertising the old one.
        $this->product->refresh()->recomputeCheapestShop();

        $this->shopMessage = 'Shop URL updated and price re-checked';
        $this->product->refresh();
    }

    /**
     * Private to the owner: shipping limits, coupons, payment quirks.
     */
    public function saveShopNotes(string $shopId, ?string $notes): void
    {
        $shop = $this->shop($shopId);

        $this->authorize('update', $shop);

        $trimmed = is_string($notes) ? trim($notes) : '';

        $shop->update(['notes' => $trimmed === '' ? null : $trimmed]);

        $this->shopMessage = 'Notes saved';
    }

    public function removeShop(string $shopId): void
    {
        $shop = $this->shop($shopId);

        $this->authorize('delete', $shop);

        $shop->delete();

        // The cheapest offer is a derived column; removing a shop can change it.
        $this->product->refresh()->recomputeCheapestShop();
        $this->product->refresh();
    }

    public function render(): View
    {
        return view('livewire.products.product-show', [
            'chart' => new PriceHistorySeries($this->product, $this->range)->fluxChart(),
            'ranges' => HistoryWindow::filters($this->historyDays()),
            'historyNotice' => $this->historyNotice(),
            'shops' => $this->product->shops()->orderBy('current_price')->get(),
            'shareUrl' => $this->product->publicShareUrl(),
            'canAddShop' => app(PlanLimits::class)->canAddShop($this->product),
            'shopLimit' => $this->product->user?->entitlements()->maxShopsPerProduct(),
        ]);
    }

    /**
     * A shop of the product this page is showing.
     *
     * The policy runs first and answers for another account's shop, which is
     * the established behaviour. The product check that follows catches the
     * case the policy allows: one of this account's own shops, on a different
     * product, which the page would otherwise edit or delete unseen.
     */
    private function shop(string $shopId): Shop
    {
        $shop = Shop::query()->findOrFail($shopId);

        $this->authorize('view', $shop);

        abort_unless($shop->product_id === $this->product->id, 404);

        return $shop;
    }

    /**
     * Null when the account may read everything. An unknown owner falls back to
     * the free ceiling rather than to unlimited.
     */
    private function historyDays(): ?int
    {
        $user = $this->product->user;

        return $user === null
            ? Entitlements::of(Plan::Free)->historyDays()
            : $user->entitlements()->historyDays();
    }

    /**
     * Says why the long ranges are missing, and offers the way to them only
     * when there is something to buy.
     *
     * @return array{reason: string, url: ?string}|null
     */
    private function historyNotice(): ?array
    {
        $maxDays = $this->historyDays();

        if ($maxDays === null) {
            return null;
        }

        return [
            'reason' => "Your plan shows the last {$maxDays} days. Pro shows the full history.",
            'url' => BillingGate::isOpen() ? route('app.billing') : null,
        ];
    }
}
