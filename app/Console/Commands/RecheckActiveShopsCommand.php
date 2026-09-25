<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProUsers;
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
final class RecheckActiveShopsCommand extends Command
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
            // Never a reference shop: its page is known to refuse us, so a
            // check spends a fetch to learn nothing and counts the refusal
            // against the row's health until `dead_after` switches it off.
            // Asking again is the retry command's job, on its own schedule.
            ->scheduled()
            ->where(function (EloquentQueryBuilder $q) use ($proCutoff, $freeCutoff): void {
                $q->whereNull('last_checked_at')
                    ->orWhere(fn (EloquentQueryBuilder $due): EloquentQueryBuilder => $this->duePerPlan($due, Plan::Pro, $proCutoff))
                    ->orWhere(fn (EloquentQueryBuilder $due): EloquentQueryBuilder => $this->duePerPlan($due, Plan::Free, $freeCutoff))
                    ->orWhere(fn (EloquentQueryBuilder $boundary): EloquentQueryBuilder => $this->dueBundleBoundary($boundary))
                    ->orWhere(fn (EloquentQueryBuilder $boundary): EloquentQueryBuilder => $this->dueDealStart($boundary));
            })
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->oldest('last_checked_at');
    }

    /**
     * @param  EloquentQueryBuilder<Shop>  $query
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueBundleBoundary(EloquentQueryBuilder $query): EloquentQueryBuilder
    {
        return $query
            ->whereNotNull('single_item_price')
            ->whereNotNull('bundle_quantity')
            ->whereNotNull('bundle_total_price')
            ->where(function (EloquentQueryBuilder $boundary): void {
                $boundary->where(function (EloquentQueryBuilder $activation): void {
                    $activation->whereNotNull('promotion_starts_at')
                        ->where('promotion_starts_at', '<=', now())
                        ->whereColumn('last_checked_at', '<', 'promotion_starts_at')
                        ->where(function (EloquentQueryBuilder $end): void {
                            $end->whereNull('promotion_ends_at')->orWhere('promotion_ends_at', '>=', now());
                        })
                        ->whereColumn('current_price', 'single_item_price');
                })->orWhere(function (EloquentQueryBuilder $expiry): void {
                    $expiry->whereNotNull('promotion_ends_at')
                        ->where('promotion_ends_at', '<', now())
                        ->whereColumn('current_price', '!=', 'single_item_price');
                });
            });
    }

    /**
     * A deal without bundle terms that started since the row was last read.
     * An announced deal is tracked at the price before it (see AhApiSource),
     * so without this the row shows the deal as running beside that price
     * until its normal recheck — up to a day on a free account. Bundles have
     * their own rule above.
     *
     * @param  EloquentQueryBuilder<Shop>  $query
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueDealStart(EloquentQueryBuilder $query): EloquentQueryBuilder
    {
        return $query
            ->whereNull('bundle_quantity')
            ->whereNotNull('promotion_starts_at')
            ->where('promotion_starts_at', '<=', now())
            ->whereColumn('last_checked_at', '<', 'promotion_starts_at')
            ->where(function (EloquentQueryBuilder $end): void {
                $end->whereNull('promotion_ends_at')->orWhere('promotion_ends_at', '>=', now());
            });
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
