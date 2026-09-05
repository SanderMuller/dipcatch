<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use App\Support\Config as DipConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

#[Signature('dipcatch:recheck-offers')]
#[Description('Dispatch CheckShopPrice jobs for offers that are due for a recheck.')]
class RecheckActiveShopsCommand extends Command
{
    public function handle(): int
    {
        $batchSize = DipConfig::int('dipcatch.scheduler.batch_size', 200);

        // Two passes, not a scan over users: each pass is one query with the
        // plan's own cutoff, restricted to that plan's owners by a subquery.
        // Pro runs first — it is the smaller set and the tighter cutoff, so
        // the faster cadence it pays for is not spent on the free backlog.
        $dispatched = $this->dispatchDue(Plan::Pro, $batchSize);
        $dispatched += $this->dispatchDue(Plan::Free, $batchSize - $dispatched);

        $this->info("Dispatched {$dispatched} CheckShopPrice jobs.");

        return self::SUCCESS;
    }

    private function dispatchDue(Plan $plan, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $jitterMinutes = DipConfig::int('dipcatch.recheck.jitter_minutes', 30);
        $cutoff = now()->subHours(Entitlements::of($plan)->recheckIntervalHours());
        $dispatched = 0;

        $this->dueQuery($plan, $cutoff)
            ->limit($limit)
            ->each(function (Shop $shop) use ($jitterMinutes, &$dispatched): void {
                $delay = random_int(0, max(0, $jitterMinutes * 60));
                dispatch(new CheckShopPrice($shop))
                    ->delay(now()->addSeconds($delay));
                $dispatched++;
            });

        return $dispatched;
    }

    /**
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueQuery(Plan $plan, mixed $cutoff): EloquentQueryBuilder
    {
        return Shop::query()
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereHas('product', function (EloquentQueryBuilder $q) use ($plan): void {
                $q->where('active', true);

                $plan->isPro()
                    ? $q->whereIn('user_id', ProUsers::ids())
                    : $q->whereNotIn('user_id', ProUsers::ids());
            })
            ->where(function (EloquentQueryBuilder $q) use ($cutoff): void {
                $q->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $cutoff);
            })
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->oldest('last_checked_at');
    }
}
