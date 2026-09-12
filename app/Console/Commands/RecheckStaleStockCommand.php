<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('dipcatch:recheck-stock {--before= : Only rows last checked before this time. Quote a value with a space: --before="2026-09-09 15:00". Relative values work too: --before="-2 hours"} {--limit=500} {--dry-run}')]
#[Description('Recheck shops whose stored stock predates a fix, so a corrected reading does not wait for the schedule.')]
class RecheckStaleStockCommand extends Command
{
    public function handle(): int
    {
        $before = $this->option('before');

        if (! is_string($before) || $before === '') {
            $this->error('Give --before, so the run is scoped to rows written before a known fix.');
            $this->line('Quote a value with a space in it: --before="2026-09-09 15:00"');

            return self::FAILURE;
        }

        try {
            $cutoff = CarbonImmutable::parse($before);
        } catch (InvalidArgumentException) {
            $this->error("Could not read --before={$before} as a time.");

            return self::FAILURE;
        }

        // Only rows claiming the product can be bought: a row already saying
        // out of stock or unknown is not the one a fixed mapping changes,
        // and every recheck costs the shop a request.
        $shops = Shop::query()
            ->where('active', true)
            ->where('current_in_stock', true)
            ->where('last_checked_at', '<', $cutoff)
            ->limit((int) $this->option('limit'))
            ->get();

        if ($this->option('dry-run')) {
            $this->info("{$shops->count()} shop(s) would be rechecked.");

            return self::SUCCESS;
        }

        $shops->each(function (Shop $shop): void {
            dispatch(new CheckShopPrice($shop));
        });

        $this->info("Dispatched {$shops->count()} recheck(s).");

        return self::SUCCESS;
    }
}
