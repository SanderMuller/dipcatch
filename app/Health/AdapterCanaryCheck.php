<?php declare(strict_types=1);

namespace App\Health;

use App\Enums\CanaryOutcome;
use App\Models\AdapterCanaryResult;
use App\Support\CanaryEntries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Config;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Reports what the last canary run learned.
 *
 * Everything that needs a person is a failure, deliberately. `only_on_failure`
 * drops every non-failed result before notifying, so a warning is visible on
 * the health page and silent in the inbox. "This adapter has no canary",
 * "the command stopped running" and "this canary has been blind for days"
 * all mean the canary is not watching something, which is the same class of
 * problem as an adapter that broke.
 */
final class AdapterCanaryCheck extends Check
{
    public function run(): Result
    {
        $configured = array_keys(CanaryEntries::configured());

        $uncovered = array_values(array_diff(CanaryEntries::hostAdapterKeys(), $configured));

        /** @var EloquentCollection<int, AdapterCanaryResult> $rows */
        $rows = AdapterCanaryResult::query()
            ->whereIn(AdapterCanaryResult::ADAPTER, $configured)
            ->get();

        $seen = $rows->map(fn (AdapterCanaryResult $row): string => $row->adapter)->all();
        $missing = array_values(array_diff($configured, $seen));

        $failing = $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome->needsAttention());

        $failRuns = Config::integer('canary.unreachable_fail_runs');
        $blind = $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::Unreachable
            && $row->consecutive_unreachable >= $failRuns);

        $stale = $this->staleAdapters($rows);

        $result = Result::make()->meta([
            'configured' => count($configured),
            'ok' => $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::Ok)->count(),
            'rot' => $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::Rot)->count(),
            'bad_url' => $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::BadUrl)->count(),
            'unreachable' => $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::Unreachable)->count(),
            'uncovered_adapters' => $uncovered,
        ]);

        $failures = [];

        if ($failing->isNotEmpty()) {
            $failures[] = $failing
                ->map(fn (AdapterCanaryResult $row): string => "{$row->adapter}: {$row->outcome->value} ({$row->detail})")
                ->implode('; ');
        }

        if ($missing !== [] || $uncovered !== []) {
            $names = implode(', ', array_merge($missing, $uncovered));
            $failures[] = "no canary result for: {$names}";
        }

        if ($stale !== []) {
            $failures[] = 'canary results are stale for: ' . implode(', ', $stale);
        }

        if ($blind->isNotEmpty()) {
            $failures[] = 'unreachable for ' . $failRuns . '+ runs: ' . $blind->pluck(AdapterCanaryResult::ADAPTER)->implode(', ');
        }

        if ($failures !== []) {
            return $result
                ->failed(implode(' | ', $failures))
                ->shortSummary('needs attention');
        }

        $wobbling = $rows->filter(fn (AdapterCanaryResult $row): bool => $row->outcome === CanaryOutcome::Unreachable
            && $row->consecutive_unreachable >= 2);

        if ($wobbling->isNotEmpty()) {
            return $result
                ->warning('unreachable on consecutive runs: ' . $wobbling->pluck(AdapterCanaryResult::ADAPTER)->implode(', '))
                ->shortSummary('unreachable');
        }

        return $result
            ->ok('Every adapter canary read its page.')
            ->shortSummary(count($configured) . ' adapters');
    }

    /**
     * Stale is measured per row rather than on the newest one: a single fresh
     * row must not mask twenty that stopped being written.
     *
     * @param EloquentCollection<int, AdapterCanaryResult> $rows
     * @return list<string>
     */
    private function staleAdapters(EloquentCollection $rows): array
    {
        $cutoff = CarbonImmutable::now()->subHours(Config::integer('canary.stale_after_hours'));

        return $rows
            ->filter(fn (AdapterCanaryResult $row): bool => $row->checked_at->isBefore($cutoff))
            ->map(fn (AdapterCanaryResult $row): string => $row->adapter)
            ->values()
            ->all();
    }
}
