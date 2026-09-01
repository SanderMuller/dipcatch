<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Tables;

use App\Models\Product;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->with('cheapestShop'))
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

                TextColumn::make('cheapest_price')
                    ->label('Cheapest')
                    ->state(fn (Product $record): string => MoneyFormatter::format(
                        $record->cheapest_price === null ? null : (string) $record->cheapest_price,
                        $record->currency,
                    ))
                    ->sortable(),

                TextColumn::make('cheapest_shop_unit_price')
                    ->label('Unit price')
                    ->state(function (Product $record): string {
                        $unitPrice = $record->cheapestShop?->unitPrice();

                        if ($unitPrice === null) {
                            return '—';
                        }

                        return MoneyFormatter::format($unitPrice, $record->currency) . ' ' . $record->cheapestShop?->unitPriceLabel();
                    }),

                TextColumn::make('shops_count')
                    ->label('Shops')
                    ->counts('shops')
                    ->sortable(),

                IconColumn::make('active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('active'),
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
}
