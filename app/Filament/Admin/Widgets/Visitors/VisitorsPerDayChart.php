<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\PageViewStats;
use Filament\Widgets\ChartWidget;

final class VisitorsPerDayChart extends ChartWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Visitors per day';

    protected ?string $maxHeight = '280px';

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, borderColor: string, backgroundColor: string}>}
     */
    protected function getData(): array
    {
        $range = $this->visitorRange();
        $days = app(PageViewStats::class)->dailyVisitors($range->from, $range->until);

        return [
            'labels' => array_keys($days),
            'datasets' => [
                [
                    'label' => 'Marketing',
                    'data' => array_values(array_map(static fn (array $day): int => $day[PageViewSection::Marketing->value], $days)),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'App',
                    'data' => array_values(array_map(static fn (array $day): int => $day[PageViewSection::App->value], $days)),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => '#3b82f6',
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
