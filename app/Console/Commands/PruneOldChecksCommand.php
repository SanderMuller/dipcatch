<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\ProUsers;
use App\Jobs\PruneOldHistory;
use App\Models\LargeDropCheck;
use App\Models\Product;
use App\Models\TargetPriceEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('dipcatch:prune-checks')]
#[Description('Prune price_checks / price_drop_events / cheapest_history older than 365 days, keeping at least 50 most-recent rows per offer/product, and target_price_events and large_drop_checks older than 365 days.')]
final class PruneOldChecksCommand extends Command
{
    /** No plan keeps price history longer; the plan pages show this. */
    public const int RETAIN_DAYS = 365;

    public function handle(): int
    {
        $this->stampKeptHistory();

        // The daily digest and the price changes list read these, and no
        // plan keeps history longer.
        $deleted = TargetPriceEvent::query()->where('fired_at', '<', now()->subDays(self::RETAIN_DAYS))->delete();
        $reachedDeleted = is_int($deleted) ? $deleted : 0;
        $deleted = LargeDropCheck::query()->where('asked_at', '<', now()->subDays(self::RETAIN_DAYS))->delete();
        $recheckedDeleted = is_int($deleted) ? $deleted : 0;

        // The rest is a pass over every product and shop, which can outgrow the
        // App's few awake minutes after a scheduled run. It runs on the queue.
        dispatch(new PruneOldHistory());

        $this->info("Pruned {$reachedDeleted} target_price_events and {$recheckedDeleted} large_drop_checks; queued the price_checks, price_drop_events and cheapest_history pass.");

        return self::SUCCESS;
    }

    /**
     * Marks the point from which an entitled account's history is kept for
     * good. One statement over the `ProUsers::ids()` subquery rather than a
     * plan lookup per product, and `history_kept_from IS NULL` makes it
     * write-once: cancelling stops new products being stamped, it never
     * retracts a stamp already there.
     *
     * The stamp is the product's own `created_at`, not the moment it was
     * written. Stamping "now" would protect nothing that already exists,
     * so a subscriber's first year would still be pruned away as it aged
     * past the cutoff — the opposite of what they are paying for.
     */
    private function stampKeptHistory(): void
    {
        Product::query()
            ->whereNull('history_kept_from')
            ->whereIn('user_id', ProUsers::ids())
            ->update(['history_kept_from' => DB::raw('created_at')]);
    }
}
