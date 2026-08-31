<?php declare(strict_types=1);

namespace App\Health;

use App\Models\CheckjebonPrice;
use App\Models\Shop;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Alerts when the local checkjebon.nl dataset goes stale. Rechecks keep
 * serving the last-known price as `Ok` when upstream stops updating, so
 * without this check nothing ever fires. Upstream skips single days
 * (observed), so the warn threshold tolerates a one-day gap.
 */
class CheckjebonFreshnessCheck extends Check
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
        $inUse = Shop::query()
            ->where('active', true)
            ->where('adapter_key', 'checkjebon')
            ->exists();

        if (! $inUse) {
            return Result::make()
                ->ok('No active shops use the checkjebon dataset.')
                ->shortSummary('idle');
        }

        $newest = CheckjebonPrice::query()->latest('refreshed_at')->first()?->refreshed_at;

        if ($newest === null) {
            return Result::make()
                ->failed('Checkjebon dataset is empty — run dipcatch:refresh-checkjebon.')
                ->shortSummary('empty');
        }

        $ageHours = (int) $newest->diffInHours(now());

        $result = Result::make()
            ->meta([
                'newest_refreshed_at' => $newest->toIso8601String(),
                'age_hours' => $ageHours,
                'warn_after_hours' => $this->warnAfterHours,
                'fail_after_hours' => $this->failAfterHours,
            ])
            ->shortSummary("{$ageHours}h old");

        if ($ageHours >= $this->failAfterHours) {
            return $result->failed("Checkjebon dataset is {$ageHours}h old (fail threshold {$this->failAfterHours}h).");
        }

        if ($ageHours >= $this->warnAfterHours) {
            return $result->warning("Checkjebon dataset is {$ageHours}h old (warn threshold {$this->warnAfterHours}h).");
        }

        return $result->ok('Checkjebon dataset is fresh.');
    }
}
