<?php declare(strict_types=1);

namespace App\Livewire;

use App\Billing\PlanLimits;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
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
class Dashboard extends Component
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
            'canAddProduct' => app(PlanLimits::class)->canAddProduct($this->user()),
            'hasAnyProduct' => $watching->isNotEmpty(),
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
            ->whereNotNull('last_notified_price')
            // One query each for the whole list rather than one per row.
            ->with(['cheapestShop', 'shops'])
            ->latest('last_notified_at')
            ->limit(10)
            ->get();
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
            ->with('cheapestShop')
            ->latest('created_at')
            ->limit(6)
            ->get();
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
