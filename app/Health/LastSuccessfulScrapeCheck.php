<?php declare(strict_types=1);

namespace App\Health;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class LastSuccessfulScrapeCheck extends Check
{
    private int $warnAfterHours = 48;

    private int $failAfterHours = 96;

    public function warnAfterHours(int $hours): self
    {
        $this->warnAfterHours = $hours;

        return $this;
    }

    public function failAfterHours(int $hours): self
    {
        $this->failAfterHours = $hours;

        return $this;
    }

    public function run(): Result
    {
        $activeCount = Product::query()->where('active', true)->count();

        if ($activeCount === 0) {
            return Result::make()
                ->ok('No active products being tracked.')
                ->shortSummary('idle');
        }

        $stale = Product::query()
            ->where('active', true)
            ->where(function (EloquentQueryBuilder $query): void {
                $query->whereNull('last_success_at')
                    ->orWhere('last_success_at', '<', now()->subHours($this->warnAfterHours));
            })
            ->count();

        $result = Result::make()
            ->meta([
                'active_products' => $activeCount,
                'stale_products' => $stale,
                'warn_after_hours' => $this->warnAfterHours,
                'fail_after_hours' => $this->failAfterHours,
            ])
            ->shortSummary("{$stale}/{$activeCount} stale");

        if ($stale === 0) {
            return $result->ok('All active products have a recent successful scrape.');
        }

        // Use `last_success_at` (set only on Ok scrapes by RecordScrape) so a
        // product failing every 15 minutes still escalates from warning to
        // failed once the critical threshold is crossed — fresh
        // `last_checked_at` from retries no longer masks repeated failures.
        $criticallyStale = Product::query()
            ->where('active', true)
            ->where(function (EloquentQueryBuilder $query): void {
                $query->whereNull('last_success_at')
                    ->orWhere('last_success_at', '<', now()->subHours($this->failAfterHours));
            })
            ->exists();

        if ($criticallyStale) {
            return $result->failed("{$stale} active product(s) failing or unscraped for over {$this->failAfterHours}h.");
        }

        return $result->warning("{$stale} active product(s) failing or unscraped for over {$this->warnAfterHours}h.");
    }
}
