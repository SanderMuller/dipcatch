<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use App\Support\Config as DipConfig;
use App\Support\RecheckJitter;
use Carbon\CarbonInterface;
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
        // Clamp the range the draw comes from, never the drawn value: a
        // `min($delay, 900)` would pile every over-ceiling draw onto exactly
        // 900s, turning a spread into a spike.
        $jitterSeconds = RecheckJitter::maxSeconds();
        $dispatched = 0;

        $this->dueQuery()
            ->limit(DipConfig::int('dipcatch.scheduler.batch_size', 200))
            ->each(function (Shop $shop) use ($jitterSeconds, &$dispatched): void {
                $delay = random_int(0, $jitterSeconds);
                dispatch(new CheckShopPrice($shop))
                    ->delay(now()->addSeconds($delay));
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} CheckShopPrice jobs.");

        return self::SUCCESS;
    }

    /**
     * Offers that are due, in one query with one ordering.
     *
     * Each plan brings its own cutoff — Pro pays for a shorter one — but
     * both plans compete in a single oldest-first queue. Splitting this into
     * a pass per plan would let a large enough Pro backlog fill the batch
     * every tick and leave free accounts unchecked forever.
     *
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueQuery(): EloquentQueryBuilder
    {
        $proCutoff = $this->cutoff(Plan::Pro);
        $freeCutoff = $this->cutoff(Plan::Free);

        return Shop::query()
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereHas('product', function (EloquentQueryBuilder $q): void {
                $q->where('active', true);
            })
            ->where(function (EloquentQueryBuilder $q) use ($proCutoff, $freeCutoff): void {
                $q->whereNull('last_checked_at')
                    ->orWhere(fn (EloquentQueryBuilder $due): EloquentQueryBuilder => $this->duePerPlan($due, Plan::Pro, $proCutoff))
                    ->orWhere(fn (EloquentQueryBuilder $due): EloquentQueryBuilder => $this->duePerPlan($due, Plan::Free, $freeCutoff));
            })
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->oldest('last_checked_at');
    }

    /**
     * @param  EloquentQueryBuilder<Shop>  $query
     * @return EloquentQueryBuilder<Shop>
     */
    private function duePerPlan(EloquentQueryBuilder $query, Plan $plan, CarbonInterface $cutoff): EloquentQueryBuilder
    {
        return $query
            ->where('last_checked_at', '<', $cutoff)
            ->whereHas('product', function (EloquentQueryBuilder $q) use ($plan): void {
                $plan->isPro()
                    ? $q->whereIn('user_id', ProUsers::ids())
                    : $q->whereNotIn('user_id', ProUsers::ids());
            });
    }

    private function cutoff(Plan $plan): CarbonInterface
    {
        return now()->subHours(Entitlements::of($plan)->recheckIntervalHours());
    }
}
