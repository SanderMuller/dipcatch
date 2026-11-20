<?php declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Visitors\AppUsageWidget;
use App\Filament\Admin\Widgets\Visitors\SignupSourcesWidget;
use App\Filament\Admin\Widgets\Visitors\TopCountriesWidget;
use App\Filament\Admin\Widgets\Visitors\TopDevicesWidget;
use App\Filament\Admin\Widgets\Visitors\TopPagesWidget;
use App\Filament\Admin\Widgets\Visitors\TopReferrersWidget;
use App\Filament\Admin\Widgets\Visitors\TopUtmCampaignsWidget;
use App\Filament\Admin\Widgets\Visitors\TopUtmSourcesWidget;
use App\Filament\Admin\Widgets\Visitors\VisitorRange;
use App\Filament\Admin\Widgets\Visitors\VisitorsPerDayChart;
use App\Filament\Admin\Widgets\Visitors\VisitorStatsWidget;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Page views and visitors from the in-house analytics
 * (specs/visitor-analytics.md). Totals only; no widget shows one person's
 * visits.
 */
final class Visitors extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'visitors';

    protected static ?string $title = 'Visitors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = -1;

    public function filtersForm(Schema $schema): Schema
    {
        $isCustom = static fn (Get $get): bool => $get('range') === 'custom';

        return $schema->components([
            Select::make('range')
                ->label('Range')
                ->options(VisitorRange::PRESETS)
                ->default(VisitorRange::DEFAULT_PRESET)
                ->selectablePlaceholder(false),
            DatePicker::make('from')->label('From')->maxDate(now())->visible($isCustom),
            DatePicker::make('until')->label('Until')->maxDate(now())->visible($isCustom),
        ]);
    }

    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [
            VisitorStatsWidget::class,
            VisitorsPerDayChart::class,
            TopPagesWidget::class,
            TopReferrersWidget::class,
            TopUtmSourcesWidget::class,
            TopUtmCampaignsWidget::class,
            TopCountriesWidget::class,
            TopDevicesWidget::class,
            AppUsageWidget::class,
            SignupSourcesWidget::class,
        ];
    }
}
