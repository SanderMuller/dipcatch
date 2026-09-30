<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\FailedShopPages\Tables;

use App\Enums\ProbeFailure;
use App\Models\FailedShopPage;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Str;

final class FailedShopPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->with('user'))
            ->columns([
                TextColumn::make('host')
                    ->label('Shop')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('url')
                    ->label('Page')
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('failure')
                    ->badge()
                    ->formatStateUsing(fn (ProbeFailure $state): string => Str::headline($state->value))
                    ->description(fn (FailedShopPage $record): ?string => $record->reason),

                TextColumn::make('times')
                    ->label('Tries')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('user.email')
                    ->label('Last tried by')
                    ->placeholder('Deleted account')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_failed_at')
                    ->label('Last try')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('failure')
                    ->options(collect(FailedShopPage::recordable())->mapWithKeys(fn (ProbeFailure $failure): array => [$failure->value => Str::headline($failure->value)])->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (FailedShopPage $record): string => $record->url)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No pages we could not read')
            ->emptyStateDescription('A shop page someone tries to add and DipCatch cannot read shows up here.')
            ->defaultSort('last_failed_at', 'desc');
    }
}
