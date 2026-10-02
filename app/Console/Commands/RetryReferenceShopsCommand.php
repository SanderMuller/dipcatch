<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProbeFailure;
use App\Enums\ShopKind;
use App\Jobs\RetryReferenceShop;
use App\Models\Shop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Config;

/**
 * Asks the shops kept as links whether they will talk yet.
 *
 * A block is a fact about today. WAF rules change, fingerprints shift, shops
 * replatform, and this app's own fetcher improves — dierapotheker.nl spent a
 * day on a blocked list while the real fault was a trailing slash DipCatch was
 * stripping, and it took a person noticing that nine failures against five
 * successes could not be chance. A weekly pass would have found it with nobody
 * looking.
 *
 * That is what makes the list of blocked shops worth keeping at all. Without
 * this it is a record of apologies; with it, every URL ever refused is a
 * standing claim on a future the fetcher gets better at, and coverage only
 * moves one way.
 */
#[Signature('dipcatch:retry-reference-shops {--limit=50 : Links per run} {--dry-run : Report what would be tried, fetch nothing}')]
#[Description('Retry the shops kept as links, and start tracking any whose page has become readable.')]
final class RetryReferenceShopsCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = $this->dueQuery()->limit(max(1, (int) $this->option('limit')))->get(['id', 'url']);

        if ($due->isEmpty()) {
            $this->info('No links are due a retry.');

            return self::SUCCESS;
        }

        foreach ($due as $shop) {
            if ($dryRun) {
                $this->line("would retry {$shop->url}");

                continue;
            }

            // A job per link: each one probes a page, and together they
            // can outlast the App's few awake minutes after a scheduled run.
            dispatch(new RetryReferenceShop($shop->id));
        }

        $this->info(sprintf($dryRun ? 'Would retry %d link(s).' : 'Queued %d link(s) for a retry.', $due->count()));

        return self::SUCCESS;
    }

    /**
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueQuery(): EloquentQueryBuilder
    {
        $cutoff = now()->subDays(Config::integer('dipcatch.reference.retry_every_days'));

        return Shop::query()
            ->where('kind', ShopKind::Reference->value)
            ->where('active', true)
            // The probe always refuses a comparison site: a retry can never
            // make it a shop.
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query
                ->whereNull('unreadable_reason')
                ->orWhere('unreadable_reason', '!=', ProbeFailure::NotAShop->value))
            ->whereHas('product', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('active', true))
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query
                ->whereNull('retried_at')
                ->orWhere('retried_at', '<', $cutoff))
            ->orderByRaw('retried_at IS NULL DESC')
            ->oldest('retried_at');
    }
}
