<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmptySearches\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

final class EmptySearchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->with('user'))
            ->columns([
                TextColumn::make('term')
                    ->label('Searched for')
                    ->searchable(),

                TextColumn::make('times')
                    ->label('Times')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('user.email')
                    ->label('Account')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('first_searched_at')
                    ->label('First')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_searched_at')
                    ->label('Last')
                    ->dateTime()
                    ->sortable(),
            ])
            ->emptyStateHeading('No searches without results')
            ->emptyStateDescription('A search in the header that finds nothing shows up here.')
            ->defaultSort('last_searched_at', 'desc');
    }
}
