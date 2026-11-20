<?php declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatDimension;
use App\Analytics\Models\PageView;
use App\Analytics\Models\PageViewDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns page views into numbers. The rollup and the admin
 * page both use `aggregate()`, so a past day read from the totals and today
 * read from the raw rows are counted the same way.
 *
 * An anonymous visitor cannot be followed across days, by design. Visitors
 * over a range are therefore visitor-days: the sum of the daily counts.
 *
 * @phpstan-type StatRow array{date: string, section: string, dimension: string, value: string, views: int, visitors: int, users: int}
 * @phpstan-type TopRow array{value: string, views: int, visitors: int}
 */
final class PageViewStats
{
    /**
     * Rebuilds the totals for one day. A rerun replaces the day's rows, so
     * it always gives the same result.
     */
    public function rollup(CarbonImmutable $day): int
    {
        $rows = $this->aggregate($day);

        DB::transaction(function () use ($day, $rows): void {
            PageViewDailyStat::query()->whereDate(PageViewDailyStat::DATE, $day->toDateString())->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                PageViewDailyStat::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * The totals for one day, computed from the raw rows: every dimension,
     * or only the given one.
     *
     * @return list<StatRow>
     */
    public function aggregate(CarbonImmutable $day, ?StatDimension $only = null): array
    {
        $start = $day->startOfDay();
        $end = $start->addDay();
        $rows = [];

        foreach ($only === null ? StatDimension::cases() : [$only] as $dimension) {
            $results = PageView::query()
                ->toBase()
                ->selectRaw('section as section')
                ->selectRaw($dimension->valueExpression() . ' as value')
                ->selectRaw('COUNT(*) as views')
                ->selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS TEXT), visitor_hash)) as visitors')
                ->selectRaw('COUNT(DISTINCT user_id) as users')
                ->where(PageView::VIEWED_AT, '>=', $start)
                ->where(PageView::VIEWED_AT, '<', $end)
                ->groupByRaw($dimension->groupExpression())
                ->get();

            foreach ($results as $result) {
                $rows[] = self::statRow($result, $start->toDateString(), $dimension->value);
            }
        }

        return $rows;
    }

    /**
     * The rows for a range of days: stored totals for past days, and today
     * computed live, because today is only rolled up after midnight.
     *
     * @return Collection<int, StatRow>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $until, StatDimension $dimension, ?PageViewSection $section = null): Collection
    {
        $from = $from->startOfDay();
        $until = $until->startOfDay();
        $today = now()->toImmutable()->startOfDay();

        /** @var Collection<int, StatRow> $stored */
        $stored = PageViewDailyStat::query()
            ->toBase()
            ->where(PageViewDailyStat::DIMENSION, $dimension->value)
            ->whereDate(PageViewDailyStat::DATE, '>=', $from->toDateString())
            ->whereDate(PageViewDailyStat::DATE, '<=', $until->min($today->subDay())->toDateString())
            ->when($section !== null, static fn (QueryBuilder $query): QueryBuilder => $query->where(PageViewDailyStat::SECTION, $section?->value))
            ->get()
            ->map(static fn (object $row): array => self::statRow($row, substr(self::text($row->date ?? ''), 0, 10), self::text($row->dimension ?? '')));

        if ($until->lessThan($today) || $from->greaterThan($today)) {
            return $stored->values();
        }

        $live = collect($this->aggregate($today, $dimension))
            ->filter(static fn (array $row): bool => $section === null || $row['section'] === $section->value);

        return $stored->concat($live)->values();
    }

    /**
     * Page views and visitor-days over a range.
     *
     * @return array{views: int, visitors: int}
     */
    public function totals(CarbonImmutable $from, CarbonImmutable $until, ?PageViewSection $section = null): array
    {
        $rows = $this->rows($from, $until, StatDimension::Total, $section);

        return [
            'views' => self::number($rows->sum('views')),
            'visitors' => self::number($rows->sum('visitors')),
        ];
    }

    /**
     * Visitors per day and section, every day in the range present.
     *
     * @return array<string, array<string, int>> date => section => visitors
     */
    public function dailyVisitors(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $days = [];

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            $days[$day->toDateString()] = array_fill_keys(array_map(static fn (PageViewSection $section): string => $section->value, PageViewSection::cases()), 0);
        }

        foreach ($this->rows($from, $until, StatDimension::Total) as $row) {
            if (isset($days[$row['date']][$row['section']])) {
                $days[$row['date']][$row['section']] += $row['visitors'];
            }
        }

        return $days;
    }

    /**
     * @return list<TopRow>
     */
    public function top(StatDimension $dimension, CarbonImmutable $from, CarbonImmutable $until, ?PageViewSection $section = null, int $limit = 10): array
    {
        /** @var list<TopRow> */
        return $this->rows($from, $until, $dimension, $section)
            ->groupBy('value')
            ->map(static fn (Collection $rows, string $value): array => [
                'value' => $value,
                'views' => self::number($rows->sum('views')),
                'visitors' => self::number($rows->sum('visitors')),
            ])
            ->sortByDesc('views')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Distinct linked users in the app over a range, or null when the range
     * starts on a day the prune of linked rows may already have reached.
     */
    public function appUsers(CarbonImmutable $from, CarbonImmutable $until): ?int
    {
        if (! $this->hasLinkedRowsFor($from)) {
            return null;
        }

        return $this->linkedAppViews($from, $until)->distinct()->count(PageView::USER_ID);
    }

    /**
     * Per app route: views from the totals, distinct linked users and their
     * share of all app users from the raw rows. Users and share are null when
     * `appUsers()` is null.
     *
     * @return list<array{route: string, views: int, users: int|null, share: float|null}>
     */
    public function appUsage(CarbonImmutable $from, CarbonImmutable $until, int $limit = 15): array
    {
        $views = collect($this->top(StatDimension::Route, $from, $until, PageViewSection::App, PHP_INT_MAX));
        $usersPerRoute = [];

        $allUsers = $this->appUsers($from, $until);

        if ($allUsers !== null) {
            /** @var array<string, int> $usersPerRoute */
            $usersPerRoute = $this->linkedAppViews($from, $until)
                ->toBase()
                ->selectRaw("COALESCE(route_name, '') as route")
                ->selectRaw('COUNT(DISTINCT user_id) as users')
                ->groupByRaw("COALESCE(route_name, '')")
                ->pluck('users', 'route')
                ->map(self::number(...))
                ->all();
        }

        /** @var list<array{route: string, views: int, users: int|null, share: float|null}> */
        return $views
            ->take($limit)
            ->map(static function (array $row) use ($usersPerRoute, $allUsers): array {
                $users = $allUsers === null ? null : ($usersPerRoute[$row['value']] ?? 0);

                return [
                    'route' => $row['value'],
                    'views' => $row['views'],
                    'users' => $users,
                    'share' => $users === null || $allUsers === null || $allUsers === 0 ? null : $users / $allUsers,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return StatRow
     */
    private static function statRow(object $row, string $date, string $dimension): array
    {
        return [
            'date' => $date,
            'section' => self::text($row->section ?? ''),
            'dimension' => $dimension,
            'value' => mb_substr(self::text($row->value ?? ''), 0, 255),
            'views' => self::number($row->views ?? 0),
            'visitors' => self::number($row->visitors ?? 0),
            'users' => self::number($row->users ?? 0),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function hasLinkedRowsFor(CarbonImmutable $from): bool
    {
        $linkedDays = config()->integer('analytics.retention.linked_days', 90);

        return $from->startOfDay()->greaterThanOrEqualTo(now()->toImmutable()->startOfDay()->subDays($linkedDays - 1));
    }

    /**
     * @return EloquentQueryBuilder<PageView>
     */
    private function linkedAppViews(CarbonImmutable $from, CarbonImmutable $until): EloquentQueryBuilder
    {
        return PageView::query()
            ->whereNotNull(PageView::USER_ID)
            ->where(PageView::SECTION, PageViewSection::App->value)
            ->where(PageView::VIEWED_AT, '>=', $from->startOfDay())
            ->where(PageView::VIEWED_AT, '<', $until->startOfDay()->addDay());
    }
}
