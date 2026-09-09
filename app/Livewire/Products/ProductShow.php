<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\HistoryWindow;
use App\Billing\Plan;
use App\Billing\PlanLimits;
use App\Charts\PriceHistoryChartOptions;
use App\Charts\PriceHistorySeries;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One product: its price, the shops it is tracked at, and the history chart.
 *
 * The chart's data and options come from the server so the plan window is
 * decided in one place — `$range` arrives from the client, and
 * `HistoryWindow::start()` clamps it whatever the menu offered.
 */
class ProductShow extends Component
{
    public Product $product;

    #[Url(as: 'range', except: '90')]
    public string $range = '90';

    public ?string $shopMessage = null;

    public function mount(Product $product): void
    {
        // Ownership is checked here, not left to a scoped query: this component
        // is reached by route-model binding on an id from the URL.
        $this->authorize('view', $product);

        $this->product = $product;
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
        $shop = Shop::query()->findOrFail($shopId);

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

        $this->shopMessage = 'Shop URL updated and price re-checked';
        $this->product->refresh();
    }

    /**
     * Private to the owner: shipping limits, coupons, payment quirks.
     */
    public function saveShopNotes(string $shopId, ?string $notes): void
    {
        $shop = Shop::query()->findOrFail($shopId);

        $this->authorize('update', $shop);

        $trimmed = is_string($notes) ? trim($notes) : '';

        $shop->update(['notes' => $trimmed === '' ? null : $trimmed]);

        $this->shopMessage = 'Notes saved';
    }

    public function removeShop(string $shopId): void
    {
        $shop = Shop::query()->findOrFail($shopId);

        $this->authorize('delete', $shop);

        $shop->delete();

        // The cheapest offer is a derived column; removing a shop can change it.
        $this->product->refresh()->recomputeCheapestShop();
        $this->product->refresh();
    }

    public function render(): View
    {
        return view('livewire.products.product-show', [
            'series' => (new PriceHistorySeries($this->product, $this->range))->data(),
            'chartOptions' => PriceHistoryChartOptions::forProduct($this->product)->toHtml(),
            'ranges' => HistoryWindow::filters($this->historyDays()),
            'historyNotice' => $this->historyNotice(),
            'shops' => $this->product->shops()->orderBy('current_price')->get(),
            'canAddShop' => app(PlanLimits::class)->canAddShop($this->product),
            'shopLimit' => $this->product->user?->entitlements()->maxShopsPerProduct(),
        ]);
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
