<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Filament\App\Widgets\RecentNotificationsTableWidget;
use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Filament\App\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            ActiveDropsTableWidget::class,
            RecentNotificationsTableWidget::class,
            SavingsByMonthChartWidget::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function getVisibleWidgets(): array
    {
        return $this->getWidgets();
    }
}
