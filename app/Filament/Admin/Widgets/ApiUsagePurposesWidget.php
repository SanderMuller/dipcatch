<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\ApiService;
use App\Models\ApiUsageDay;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

/** The outside service calls per day and per purpose, newest first. */
final class ApiUsagePurposesWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Outside service calls per purpose')
            ->description('Per day. A refusal is a call a daily cap kept from going out.')
            ->emptyStateHeading('No calls yet')
            ->query(ApiUsageDay::query()->where('day', '>=', now()->subDays(29)->toDateString()))
            ->defaultPaginationPageOption(10)
            ->defaultSort(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->orderByDesc('day')->orderByDesc('calls'))
            ->columns([
                TextColumn::make('day')->date()->sortable(),
                TextColumn::make('service')
                    ->formatStateUsing(fn (ApiService $state): string => $state->label())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('purpose'),
                TextColumn::make('calls')->alignEnd()->sortable(),
                TextColumn::make('failures')->alignEnd()->sortable(),
                TextColumn::make('refusals')->alignEnd()->sortable(),
                TextColumn::make('input_tokens')->label('Tokens in')->numeric()->alignEnd()->visibleFrom('lg'),
                TextColumn::make('output_tokens')->label('Tokens out')->numeric()->alignEnd()->visibleFrom('lg'),
            ]);
    }
}
