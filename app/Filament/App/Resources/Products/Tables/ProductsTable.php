<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Tables;

use App\Enums\ScrapeStatus;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Image')
                    ->circular()
                    ->imageSize(40),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('last_price')
                    ->label('Last price')
                    ->state(fn (Product $record): string => self::formatMoney(
                        $record->last_price === null ? null : (string) $record->last_price,
                        $record->currency,
                    ))
                    ->sortable(),

                TextColumn::make('diff_pct')
                    ->label('Δ vs initial')
                    ->state(fn (Product $record): ?string => self::diffFromInitial($record))
                    ->color(fn (Product $record): string => self::diffColor($record))
                    ->sortable(false),

                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('last_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?ScrapeStatus $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (?ScrapeStatus $state): string => $state === null ? '—' : $state->value),

                IconColumn::make('active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('last_status')
                    ->label('Status')
                    ->options(collect(ScrapeStatus::cases())
                        ->mapWithKeys(fn (ScrapeStatus $s): array => [$s->value => $s->value])
                        ->all()),
                TernaryFilter::make('active'),
                SelectFilter::make('currency')
                    ->options(fn (): array => Product::query()
                        ->select('currency')
                        ->distinct()
                        ->pluck('currency', 'currency')
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('pause')
                        ->label('Pause tracking')
                        ->icon(Heroicon::Pause)
                        ->color('gray')
                        ->action(fn (EloquentCollection $records) => Product::query()->whereIn('id', $records->modelKeys())->update(['active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('resume')
                        ->label('Resume tracking')
                        ->icon(Heroicon::Play)
                        ->color('success')
                        ->action(fn (EloquentCollection $records) => Product::query()->whereIn('id', $records->modelKeys())->update(['active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function formatMoney(?string $amount, string $currency): string
    {
        if ($amount === null) {
            return '—';
        }

        return $currency . ' ' . number_format((float) $amount, 2, '.', ',');
    }

    private static function diffFromInitial(Product $product): ?string
    {
        if ($product->last_price === null) {
            return null;
        }

        $initial = (float) $product->initial_price;
        if ($initial <= 0.0) {
            return null;
        }

        $delta = ((float) $product->last_price - $initial) / $initial * 100.0;

        return sprintf('%+0.1f%%', $delta);
    }

    private static function diffColor(Product $product): string
    {
        if ($product->last_price === null) {
            return 'gray';
        }

        $initial = (float) $product->initial_price;
        if ($initial <= 0.0) {
            return 'gray';
        }

        return ((float) $product->last_price < $initial) ? 'success' : 'gray';
    }

    private static function statusColor(?ScrapeStatus $status): string
    {
        return match ($status) {
            ScrapeStatus::Ok => 'success',
            ScrapeStatus::NeedsJs => 'warning',
            ScrapeStatus::Throttled, ScrapeStatus::RobotsBlocked => 'gray',
            ScrapeStatus::HttpError, ScrapeStatus::ParseError, ScrapeStatus::EmptyMatch => 'danger',
            null => 'gray',
        };
    }
}
