<?php declare(strict_types=1);

namespace App\Health;

use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Alerts when the local checkjebon.nl dataset goes stale. Rechecks keep
 * serving the last-known price as `Ok` when upstream stops updating, so
 * without this check nothing ever fires. Upstream skips single days
 * (observed), so the warn threshold tolerates a one-day gap.
 *
 * The dataset feeds two consumers: price rechecks for `checkjebon` shops,
 * and shop suggestions for every product. Age is therefore measured on the
 * OLDEST imported chain — one refreshed chain must not mask nine stale ones
 * — and a chain that has never produced a row is reported separately, since
 * no age can reveal it.
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
            ->exists()
            || Product::query()->exists();

        if (! $inUse) {
            return Result::make()
                ->ok('Nothing uses the checkjebon dataset.')
                ->shortSummary('idle');
        }

        $oldest = CheckjebonPrice::query()
            ->selectRaw('supermarket, max(refreshed_at) as chain_refreshed_at')
            ->groupBy('supermarket')
            ->orderBy('chain_refreshed_at')
            ->first();

        if ($oldest === null) {
            return Result::make()
                ->failed('Checkjebon dataset is empty — run dipcatch:refresh-checkjebon.')
                ->shortSummary('empty');
        }

        $missing = $this->chainsWithoutRows();

        if ($missing !== []) {
            return Result::make()
                ->meta(['chains_without_rows' => $missing])
                ->failed('Checkjebon chains have no rows: ' . implode(', ', $missing) . '.')
                ->shortSummary(count($missing) . ' chain(s) missing');
        }

        $oldestChain = $oldest->getAttribute('supermarket');
        $refreshedAt = $oldest->getAttribute('chain_refreshed_at');

        if (! is_string($oldestChain) || ! is_string($refreshedAt)) {
            return Result::make()
                ->failed('Checkjebon dataset is unreadable — run dipcatch:refresh-checkjebon.')
                ->shortSummary('unreadable');
        }

        $newest = CarbonImmutable::parse($refreshedAt);

        $ageHours = (int) $newest->diffInHours(now());

        $result = Result::make()
            ->meta([
                'oldest_chain' => $oldestChain,
                'oldest_chain_refreshed_at' => $newest->toIso8601String(),
                'age_hours' => $ageHours,
                'warn_after_hours' => $this->warnAfterHours,
                'fail_after_hours' => $this->failAfterHours,
            ])
            ->shortSummary("{$ageHours}h old");

        if ($ageHours >= $this->failAfterHours) {
            return $result->failed("Checkjebon chain '{$oldestChain}' is {$ageHours}h old (fail threshold {$this->failAfterHours}h).");
        }

        if ($ageHours >= $this->warnAfterHours) {
            return $result->warning("Checkjebon chain '{$oldestChain}' is {$ageHours}h old (warn threshold {$this->warnAfterHours}h).");
        }

        return $result->ok('Checkjebon dataset is fresh.');
    }

    /**
     * Chains the importer knows about that hold no rows at all — an age
     * comparison can never surface these.
     *
     * @return list<string>
     */
    private function chainsWithoutRows(): array
    {
        $withRows = CheckjebonPrice::query()->distinct()->pluck('supermarket')->all();

        /** @var list<string> $missing */
        $missing = CheckjebonChain::query()
            ->whereNotIn('chain', $withRows === [] ? [''] : $withRows)
            ->orderBy('chain')
            ->pluck('chain')
            ->all();

        return $missing;
    }
}
