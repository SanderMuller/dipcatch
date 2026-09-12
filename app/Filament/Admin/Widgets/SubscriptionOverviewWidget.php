<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

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
class SubscriptionOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $pro = $this->proCount();
        $paying = $this->payingCount();
        $trialing = $this->trialingCount();
        $comped = $this->compedCount();

        return [
            Stat::make('Pro accounts', $pro)
                ->description($this->grantBreakdown($paying, $trialing, $comped))
                ->icon('heroicon-o-user-group')
                ->color($pro > 0 ? 'success' : 'gray'),

            Stat::make('Comped', $comped)
                ->description($this->compDescription())
                ->icon('heroicon-o-gift')
                ->color($comped > 0 ? 'info' : 'gray'),

            Stat::make('Free accounts', max(0, User::query()->count() - $pro))
                ->description('Everyone not entitled to Pro')
                ->icon('heroicon-o-users')
                ->color('gray'),

            $this->blockedStat(),
        ];
    }

    private function proCount(): int
    {
        return DB::query()->fromSub(ProUsers::ids(), 'pro')->count();
    }

    /**
     * The three doors into Pro, named so the headline count never reads as
     * demand: a comp is entitlement the owner handed out, not a sale.
     */
    private function grantBreakdown(int $paying, int $trialing, int $comped): string
    {
        return $paying . ' paying · ' . $trialing . ' on trial · ' . $comped . ' comped';
    }

    private function payingCount(): int
    {
        return Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->active()
            ->notOnTrial()
            ->count();
    }

    /**
     * Both doors marked "trial": a Stripe subscription still inside its trial,
     * and an account-level `trial_ends_at` granted before any subscription
     * exists. `Subscribes::plan()` honours both, so both belong here.
     */
    private function trialingCount(): int
    {
        $onSubscription = Subscription::query()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->active()
            ->onTrial()
            ->count();

        $granted = User::query()
            ->whereNull('billing_blocked_at')
            ->where('trial_ends_at', '>', CarbonImmutable::now())
            ->whereDoesntHave('subscriptions', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('type', Plan::SUBSCRIPTION_TYPE))
            ->count();

        return $onSubscription + $granted;
    }

    private function compedCount(): int
    {
        return User::query()
            ->whereNull('billing_blocked_at')
            ->where('comped_until', '>', CarbonImmutable::now())
            ->count();
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
    private function blockedStat(): Stat
    {
        $blocked = User::query()->whereNotNull('billing_blocked_at')->count();

        return Stat::make('Blocked', $blocked)
            ->description($blocked === 0 ? 'No chargebacks lost' : 'Pro withdrawn after a lost dispute')
            ->icon('heroicon-o-shield-exclamation')
            ->color($blocked > 0 ? 'danger' : 'gray');
    }
}
