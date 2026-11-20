<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\PageViewStats;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Number;

final class VisitorStatsWidget extends StatsOverviewWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $description = 'Visitor-days: an anonymous visitor is only recognised within one day, so a visitor who comes back on three days counts three times.';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $range = $this->visitorRange();
        $stats = app(PageViewStats::class);
        $all = $stats->totals($range->from, $range->until);
        $marketing = $stats->totals($range->from, $range->until, PageViewSection::Marketing);
        $appUsers = $stats->appUsers($range->from, $range->until);

        return [
            Stat::make('Page views', Number::format($all['views'])),
            Stat::make('Visitor-days', Number::format($all['visitors'])),
            Stat::make('Marketing visitor-days', Number::format($marketing['visitors'])),
            Stat::make('App users', $appUsers === null ? 'n/a' : Number::format($appUsers))
                ->description($appUsers === null ? 'Linked visits are kept 90 days' : 'Signed in, not opted out'),
            Stat::make('Signups with a source', Number::format($this->signupsWithSource($range))),
        ];
    }

    private function signupsWithSource(VisitorRange $range): int
    {
        return User::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<', $range->until->addDay())
            ->where(fn (EloquentQueryBuilder $query) => $query
                ->whereNotNull('signup_referrer_host')
                ->orWhereNotNull('signup_utm_source')
                ->orWhereNotNull('signup_utm_medium')
                ->orWhereNotNull('signup_utm_campaign'))
            ->count();
    }
}
