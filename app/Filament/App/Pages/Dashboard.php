<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Filament\App\Widgets\GettingStartedWidget;
use App\Filament\App\Widgets\NextStepsWidget;
use App\Filament\App\Widgets\RecentNotificationsTableWidget;
use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Filament\App\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * A user without products gets only the getting-started panel: zero
     * stats and empty tables would say nothing. Once they track something
     * the data widgets take over, with the next-steps checklist on top
     * until it completes (its own canView()). Filament filters this list
     * through each widget's canView() when rendering.
     *
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        if (GettingStartedWidget::canView()) {
            return [GettingStartedWidget::class];
        }

        return [
            NextStepsWidget::class,
            StatsOverviewWidget::class,
            ActiveDropsTableWidget::class,
            RecentNotificationsTableWidget::class,
            SavingsByMonthChartWidget::class,
        ];
    }
}
