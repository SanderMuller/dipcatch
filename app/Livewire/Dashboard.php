<?php declare(strict_types=1);

namespace App\Livewire;

use App\Billing\PlanLimits;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\DashboardDigest;
use App\Support\MoneyFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

/**
 * What the account is watching, and what it has saved.
 *
 * Every figure is scoped to the signed-in user; the Filament widgets did the
 * same and nothing else enforces it.
 */
final class Dashboard extends Component
{
    public function render(): View
    {
        $activeDrops = $this->activeDrops();
        $watching = $this->watching();

        return view('livewire.dashboard', [
            'trackedProducts' => $this->trackedProducts(),
            'activeDropCount' => $activeDrops->count(),
            'activeDrops' => $activeDrops,
            'watching' => $watching,
            'lifetimeSavings' => $this->lifetimeSavings(),
            'needsSecondShop' => $this->needsSecondShop($watching),
            'canAddProduct' => app(PlanLimits::class)->canAddProduct($this->user()),
            'hasAnyProduct' => $watching->isNotEmpty(),
            'digest' => DashboardDigest::of($this->activeProducts(), $this->productsInDrop()),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function trackedProducts(): int
    {
        return Product::query()
            ->where('user_id', $this->user()->id)
            ->where('active', true)
            ->count();
    }

    /**
     * Products currently below the threshold that alerted on them.
     *
     * @return EloquentCollection<int, Product>
     */
    private function activeDrops(): EloquentCollection
    {
        return Product::query()
            ->where('user_id', $this->user()->id)
            // As "Only discounts" on the product list: a drop the card shows a
            // badge for, not a price that has climbed back to its reference.
            ->inVisibleDrop()
            // One query each for the whole list rather than one per row.
            ->with(['cheapestShop', 'shops', 'latestPriceDropEvent'])
            // Biggest first: the drop worth acting on leads, not the newest.
            ->orderByDesc(Product::liveDropPercentQuery())
            ->latest('last_notified_at')
            ->limit(10)
            ->get();
    }

    /**
     * Every product still being followed, for the digest's shopping trips,
     * ending deals and broken shops. Without the alert history: loading the
     * latest event eagerly reads every event of every product.
     *
     * @return EloquentCollection<int, Product>
     */
    private function activeProducts(): EloquentCollection
    {
        return Product::query()
            ->where('user_id', $this->user()->id)
            ->where('active', true)
            ->with(['cheapestShop', 'shops'])
            ->get();
    }

    /**
     * Every product in a visible drop, not only the ten the drop cards show.
     *
     * @return list<string>
     */
    private function productsInDrop(): array
    {
        $ids = Product::query()
            ->where('user_id', $this->user()->id)
            ->inVisibleDrop()
            ->pluck('id')
            ->all();

        return array_values(array_filter($ids, is_string(...)));
    }

    /**
     * The most recently tracked products, drops or not. Carries no `active`
     * filter, so an empty result also answers "has this account any product
     * at all" without a second query.
     *
     * @return EloquentCollection<int, Product>
     */
    private function watching(): EloquentCollection
    {
        return Product::query()
            ->where('user_id', $this->user()->id)
            // The card's figure is resolved across the shops, so they load
            // with the list rather than once per card.
            ->with(['cheapestShop', 'shops', 'latestPriceDropEvent'])
            ->latest('created_at')
            // One row of cards at five across.
            ->limit(5)
            ->get();
    }

    /**
     * DipCatch only pays off once a product is tracked at more than one shop,
     * so an account that has never done it gets one nudge. It disappears by
     * itself.
     *
     * @param  EloquentCollection<int, Product>  $watching
     */
    private function needsSecondShop(EloquentCollection $watching): bool
    {
        if ($watching->isEmpty()) {
            return false;
        }

        return ! Product::query()
            ->where('user_id', $this->user()->id)
            ->has('shops', '>=', 2)
            ->exists();
    }

    /**
     * Summed against the reference price each alert fired from, in the
     * currency most of them used — mixing currencies would produce a number
     * that means nothing.
     */
    private function lifetimeSavings(): string
    {
        $rows = PriceDropEvent::query()
            ->where('user_id', $this->user()->id)
            ->selectRaw('currency, sum(drop_abs) as total')
            ->groupBy('currency')
            ->get();

        if ($rows->isEmpty()) {
            $currency = is_string($this->user()->default_currency) && $this->user()->default_currency !== ''
                ? $this->user()->default_currency
                : 'EUR';

            return MoneyFormatter::format('0', $currency);
        }

        $primary = $rows
            ->sortByDesc(fn (object $row): float => is_numeric($row->total) ? (float) $row->total : 0.0)
            ->first();

        return MoneyFormatter::format(
            is_scalar($primary?->total) ? (string) $primary->total : '0',
            is_string($primary?->currency) ? $primary->currency : 'EUR',
        );
    }
}
