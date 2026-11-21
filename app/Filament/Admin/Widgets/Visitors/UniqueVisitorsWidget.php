<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\PageViewStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

final class UniqueVisitorsWidget extends TableWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Unique visitors per week and month')
            ->description('Signed-in users, and guests from countries where a visitor cookie is allowed. Other guests are counted per day only.')
            ->records(function (): array {
                $range = $this->visitorRange();
                $records = [];

                foreach (app(PageViewStats::class)->periods($range->from, $range->until) as $index => $row) {
                    $records[$index + 1] = [
                        'period' => $row['period']->label($row['starts_on']) . ($row['complete'] ? '' : ' (up to yesterday)'),
                        'visitors' => self::roundedVisitors($row['visitors']),
                        'users' => $row['users'],
                    ];
                }

                return $records;
            })
            ->paginated(false)
            ->emptyStateHeading('No weeks or months counted in this range')
            ->columns([
                TextColumn::make('period')->label('Period'),
                TextColumn::make('visitors')->label('Unique visitors (to the nearest 10)')->alignEnd(),
                TextColumn::make('users')->label('App users')->numeric()->alignEnd(),
            ]);
    }

    /**
     * The total includes guests counted with the visitor cookie. The CNIL's
     * consent exemption asks that such statistics are shown to the nearest
     * ten, so a small count cannot single out one visitor.
     */
    public static function roundedVisitors(int $visitors): string
    {
        if ($visitors < 10) {
            return '< 10';
        }

        return (string) Number::format((int) round($visitors, -1));
    }
}
