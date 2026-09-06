<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Billing\BillingGate;
use App\Billing\Plan;
use App\Billing\ProPrice;
use App\Billing\ProUsers;
use App\Models\StripePayment;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

/**
 * The owner's numbers, all from local tables. MRR is the count of paying
 * subscriptions times the configured price — a trialing account pays
 * nothing yet, so it is counted separately rather than folded into MRR.
 */
class RevenueOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return BillingGate::isConfigured();
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $paying = $this->payingCount();
        $trialing = $this->trialingCount();
        $cancelled = $this->cancelledThisMonth();
        $new = $this->startedThisMonth();

        return [
            Stat::make('MRR', MoneyFormatter::format(sprintf('%.2F', $paying * $this->price()), $this->currency()))
                ->description($paying . ' paying, ' . $trialing . ' on trial')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Pro accounts', $this->proCount())
                ->description($this->proDescription())
                ->icon('heroicon-o-user-group')
                ->color('primary'),

            Stat::make('This month', '+' . $new . ' / -' . $cancelled)
                ->description($this->churnLabel($cancelled, $paying + $cancelled))
                ->icon('heroicon-o-arrow-trending-up')
                ->color($cancelled > $new ? 'danger' : 'success'),

            Stat::make('Net revenue', MoneyFormatter::formatMinor($this->netRevenue(), $this->currency()))
                ->description('All payments minus refunds')
                ->icon('heroicon-o-receipt-percent')
                ->color('gray'),

            Stat::make('Trial conversion', $this->conversionLabel())
                ->description('Ended trials that stayed')
                ->icon('heroicon-o-sparkles')
                ->color('info'),
        ];
    }

    private function proCount(): int
    {
        return DB::query()->fromSub(ProUsers::ids(), 'pro')->count();
    }

    /**
     * Comps count towards this stat, because they really are entitled. Naming
     * them keeps the number from reading as demand — every money figure below
     * reads the subscription and payment tables directly and is unaffected.
     */
    private function proDescription(): string
    {
        $comped = DB::table('users')->where('comped_until', '>', now())->count();

        return $comped === 0
            ? 'Entitled to Pro right now'
            : 'Entitled to Pro right now, ' . $comped . ' of them comped';
    }

    /**
     * `keepPastDueSubscriptionsActive()` keeps a failed payment entitled to
     * Pro, which is a product decision, not revenue — MRR counts the money
     * Stripe is actually collecting.
     */
    private function payingCount(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->active()
            ->notOnTrial()
            ->where('stripe_status', '!=', 'past_due')
            ->count();
    }

    private function trialingCount(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->active()
            ->onTrial()
            ->count();
    }

    private function startedThisMonth(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->count();
    }

    private function cancelledThisMonth(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->count();
    }

    /**
     * Of the trials that have run out, how many are still subscribed. A
     * trial still running has not answered the question yet.
     */
    private function conversionLabel(): string
    {
        $ended = Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', CarbonImmutable::now());

        $total = (clone $ended)->count();

        if ($total === 0) {
            return '—';
        }

        $stayed = $ended->active()->count();

        return round($stayed / $total * 100) . '%';
    }

    private function netRevenue(): int
    {
        // One currency only: adding cents of one currency to another would
        // produce a number that means nothing.
        return (int) StripePayment::query()
            ->where('currency', $this->currency())
            ->sum('amount');
    }

    private function churnLabel(int $cancelled, int $base): string
    {
        if ($base === 0) {
            return 'No subscriptions yet';
        }

        return 'Churn ' . round($cancelled / $base * 100, 1) . '%';
    }

    private function price(): float
    {
        return (float) ProPrice::amount();
    }

    private function currency(): string
    {
        return mb_strtoupper(ProPrice::currency());
    }
}
