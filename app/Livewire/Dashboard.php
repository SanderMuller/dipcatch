<?php declare(strict_types=1);

namespace App\Livewire;

use App\Billing\PlanLimits;
use App\Charts\SavingsByMonthSeries;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Support\MoneyFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
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
        $savings = new SavingsByMonthSeries($this->user());

        return view('livewire.dashboard', [
            'trackedProducts' => $this->trackedProducts(),
            'activeDropCount' => $activeDrops->count(),
            'activeDrops' => $activeDrops,
            'watching' => $watching,
            'lifetimeSavings' => $this->lifetimeSavings(),
            'savings' => $savings->hasData() ? $savings->fluxChart() : null,
            'recentAlerts' => $this->recentAlerts(),
            'needsSecondShop' => $this->needsSecondShop($watching),
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
     * The last alerts this account was sent, whether or not they are still
     * unread. The bell clears itself on open, so without this a drop that
     * already happened is unreachable.
     *
     * @return Collection<int, array{title: string, url: ?string, percent: ?string, amount: ?string, bundle: non-falsy-string|null, sentAt: ?string}>
     */
    private function recentAlerts(): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $this->user()->id)
            // Price alerts only. A billing incident and a test notification
            // also land in this table, and neither carries a product: listed
            // here they would render as an empty row under "Recent alerts".
            ->whereIn('type', [
                PriceDropNotification::class,
                TargetPriceNotification::class,
                UnitPriceTargetNotification::class,
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                /** @var array<string, mixed> $data */
                $data = $notification->data;

                $productId = is_string($data['product_id'] ?? null) ? $data['product_id'] : null;
                $currency = is_string($data['currency'] ?? null) ? $data['currency'] : 'EUR';
                $rawPercent = $data['drop_percent'] ?? null;
                $amount = $data['drop_absolute'] ?? null;

                $percent = self::percentage($rawPercent);

                return [
                    'title' => is_string($data['title'] ?? null) ? $data['title'] : '—',
                    'url' => $productId === null ? null : route('app.products.show', $productId),
                    'percent' => $percent,
                    'amount' => is_numeric($amount) ? MoneyFormatter::format((string) $amount, $currency) : null,
                    'bundle' => is_int($data['bundle_quantity'] ?? null) && is_numeric($data['bundle_total_price'] ?? null)
                        ? $data['bundle_quantity'] . ' for ' . MoneyFormatter::format((string) $data['bundle_total_price'], $currency)
                        : null,
                    'sentAt' => $notification->created_at?->diffForHumans(),
                ];
            })
            ->values();
    }

    /** One place decides how a drop percentage reads, and its type. */
    private static function percentage(mixed $value): ?string
    {
        return is_numeric($value)
            ? number_format((float) $value, 1, '.', '') . '%'
            : null;
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
