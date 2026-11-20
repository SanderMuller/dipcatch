<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatDimension;
use App\Analytics\PageViewStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

abstract class TopListWidget extends TableWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    abstract protected function dimension(): StatDimension;

    abstract protected function listHeading(): string;

    abstract protected function emptyValueLabel(): string;

    protected function section(): ?PageViewSection
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->listHeading())
            ->records(function (): array {
                $range = $this->visitorRange();
                $rows = app(PageViewStats::class)->top($this->dimension(), $range->from, $range->until, $this->section());
                $records = [];

                foreach ($rows as $index => $row) {
                    $records[$index + 1] = [
                        'value' => $row['value'] === '' ? $this->emptyValueLabel() : $row['value'],
                        'views' => $row['views'],
                        'visitors' => $row['visitors'],
                    ];
                }

                return $records;
            })
            ->paginated(false)
            ->emptyStateHeading('No visits in this range')
            ->columns([
                TextColumn::make('value')->label($this->listHeading())->wrap(),
                TextColumn::make('views')->label('Views')->numeric()->alignEnd(),
                TextColumn::make('visitors')->label('Visitor-days')->numeric()->alignEnd(),
            ]);
    }
}
