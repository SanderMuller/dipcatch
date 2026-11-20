<?php declare(strict_types=1);

namespace App\Analytics\Console;

use App\Analytics\PageViewStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

final class RollupCommand extends Command
{
    protected $signature = 'analytics:rollup {--date= : The day to roll up, as YYYY-MM-DD. Defaults to yesterday.}';

    protected $description = 'Roll up one day of page views into daily totals';

    public function handle(PageViewStats $stats): int
    {
        $option = $this->option('date');

        try {
            $day = is_string($option) && $option !== ''
                ? CarbonImmutable::createFromFormat('!Y-m-d', $option, config()->string('app.timezone'))
                : Date::now()->toImmutable()->subDay()->startOfDay();
        } catch (Throwable) {
            $day = false;
        }

        if (! $day instanceof CarbonImmutable) {
            $this->error('The date must look like 2026-11-20.');

            return self::INVALID;
        }

        $oldestComplete = Date::now()->toImmutable()->startOfDay()->subDays(config()->integer('analytics.retention.anonymous_days', 30) - 1);

        if ($day->lessThan($oldestComplete)) {
            $this->error("The raw page views for {$day->toDateString()} may already be pruned, so a rollup would overwrite its totals with partial counts. The oldest day that can be rolled up is {$oldestComplete->toDateString()}.");

            return self::FAILURE;
        }

        $count = $stats->rollup($day);

        $this->info("Rolled up {$day->toDateString()}: {$count} rows.");

        return self::SUCCESS;
    }
}
