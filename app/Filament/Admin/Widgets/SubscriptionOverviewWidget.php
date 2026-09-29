<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Billing\Plan;
use App\Billing\PlanSource;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who is entitled to Pro, and how they got there.
 *
 * Deliberately not gated on `BillingGate` the way the revenue widget is.
 * An account can hold Pro through a comp or a hand-granted trial with no
 * Stripe configuration at all, so gating this on Stripe would hide the only
 * subscribers an installation actually has.
 *
 * Money stays with {@see RevenueOverviewWidget}: this widget counts people,
 * that one counts euros.
 */
final class SubscriptionOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $accounts = $this->accountsByStatus();
        $paying = $accounts['paying'] ?? 0;
        $trialing = $accounts['trial'] ?? 0;
        $comped = $accounts['comped'] ?? 0;
        $blocked = $accounts['blocked'] ?? 0;
        $pro = $paying + $trialing + $comped;

        return [
            Stat::make('Pro accounts', $pro)
                ->description($this->grantBreakdown($paying, $trialing, $comped))
                ->icon('heroicon-o-user-group')
                ->color($pro > 0 ? 'success' : 'gray'),

            Stat::make('Comped', $comped)
                ->description($this->compDescription())
                ->icon('heroicon-o-gift')
                ->color($comped > 0 ? 'info' : 'gray'),

            Stat::make('Free accounts', array_sum($accounts) - $pro)
                ->description('Everyone not entitled to Pro')
                ->icon('heroicon-o-users')
                ->color('gray'),

            $this->blockedStat($blocked),
        ];
    }

    /**
     * Every account counted once, by `User::planSource()`, so the widget, the
     * subscribers table and `ProUsers` cannot disagree. Counted per account,
     * not per Stripe row: a comp beside a live row is a comp, and two live rows
     * are one paying account.
     *
     * @return array<string, int>
     */
    private function accountsByStatus(): array
    {
        $accounts = [];

        foreach (User::query()->with('subscriptions')->lazyById(500) as $user) {
            $group = match ($user->planSource()) {
                PlanSource::Subscription => $user->payingSubscription()?->onTrial() === true ? 'trial' : 'paying',
                PlanSource::AccountTrial => 'trial',
                PlanSource::Comp => 'comped',
                PlanSource::Blocked => 'blocked',
                PlanSource::None => 'free',
            };
            $accounts[$group] = ($accounts[$group] ?? 0) + 1;
        }

        return $accounts;
    }

    /**
     * The three doors into Pro, named so the headline count never reads as
     * demand: a comp is entitlement the owner handed out, not a sale.
     */
    private function grantBreakdown(int $paying, int $trialing, int $comped): string
    {
        return $paying . ' paying · ' . $trialing . ' on trial · ' . $comped . ' comped';
    }

    private function compDescription(): string
    {
        $forever = User::query()
            ->whereNull('billing_blocked_at')
            ->where('comped_until', '>=', Plan::COMPED_FOREVER)
            ->count();

        return $forever === 0
            ? 'Pro without paying'
            : $forever . ' of them with no end date';
    }

    /**
     * A blocked account is still a customer — it keeps its subscription and
     * its billing portal — so it is worth seeing, not hiding.
     */
    private function blockedStat(int $blocked): Stat
    {
        return Stat::make('Blocked', $blocked)
            ->description($blocked === 0 ? 'No chargebacks lost' : 'Pro withdrawn after a lost dispute')
            ->icon('heroicon-o-shield-exclamation')
            ->color($blocked > 0 ? 'danger' : 'gray');
    }
}
