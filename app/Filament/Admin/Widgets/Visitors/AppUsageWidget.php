<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\PageViewStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

final class AppUsageWidget extends TableWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('App usage')
            ->description('Users and share count only people who did not opt out. They show n/a for a range that starts more than 90 days ago, because linked visits are not kept that long.')
            ->records(function (): array {
                $range = $this->visitorRange();
                $records = [];

                foreach (app(PageViewStats::class)->appUsage($range->from, $range->until) as $index => $row) {
                    $records[$index + 1] = [
                        'route' => $row['route'] === '' ? '(unnamed)' : $row['route'],
                        'views' => $row['views'],
                        'users' => $row['users'] ?? 'n/a',
                        'share' => $row['share'] === null ? 'n/a' : Number::percentage($row['share'] * 100, maxPrecision: 1),
                    ];
                }

                return $records;
            })
            ->paginated(false)
            ->emptyStateHeading('No app visits in this range')
            ->columns([
                TextColumn::make('route')->label('Page'),
                TextColumn::make('views')->label('Views')->alignEnd(),
                TextColumn::make('users')->label('Users')->alignEnd(),
                TextColumn::make('share')->label('Share of app users')->alignEnd(),
            ]);
    }
}
