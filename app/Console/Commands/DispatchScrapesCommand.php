<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use App\Support\Config as DipConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;

#[Signature('dipcatch:dispatch-scrapes')]
#[Description('Dispatch ScrapeProductJob for active products that are due for a recheck.')]
class DispatchScrapesCommand extends Command
{
    public function handle(): int
    {
        $batchSize = DipConfig::int('dipcatch.scheduler.batch_size', 200);
        $jitterMax = DipConfig::int('dipcatch.scheduler.jitter_seconds', 300);

        Product::query()
            ->where('active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', now()->subDay());
            })
            // Never-scraped products first, then oldest-checked. Postgres ASC
            // puts NULLs last by default, so order explicitly.
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->oldest('last_checked_at')
            ->limit($batchSize)
            ->get()
            ->each(function (Product $product) use ($jitterMax): void {
                dispatch(new ScrapeProductJob($product))
                    ->onQueue('scrapes')
                    ->delay(now()->addSeconds(random_int(0, max(0, $jitterMax))));
            });

        return self::SUCCESS;
    }
}
