<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Billing\Plan;
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
                ->description('Entitled to Pro right now')
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
        ];
    }

    private function proCount(): int
    {
        return DB::query()->fromSub(ProUsers::ids(), 'pro')->count();
    }

    private function payingCount(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->active()
            ->notOnTrial()
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

    private function netRevenue(): int
    {
        return (int) StripePayment::query()->sum('amount');
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
        $amount = config('plans.stripe.pro_amount');

        return is_numeric($amount) ? (float) $amount : 0.0;
    }

    private function currency(): string
    {
        $currency = config('plans.stripe.pro_currency');

        return mb_strtoupper(is_string($currency) && $currency !== '' ? $currency : 'EUR');
    }
}
