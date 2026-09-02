<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Tables;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
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
            // Filament shows one empty state for both "nothing tracked yet"
            // and "search/filter matched nothing"; only the former gets the
            // onboarding copy and CTA.
            ->emptyStateIcon(fn (): Heroicon => self::hasNoProducts() ? Heroicon::OutlinedShoppingBag : Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading(fn (): string => self::hasNoProducts() ? 'No products yet' : 'No matching products')
            ->emptyStateDescription(fn (): string => self::hasNoProducts()
                ? 'Paste a product link from any webshop and DipCatch starts watching the price.'
                : 'Try a different search or clear the filters.')
            ->emptyStateActions([
                Action::make('track')
                    ->label('Track a product')
                    ->icon(Heroicon::Plus)
                    ->url(ProductResource::getUrl('create'))
                    ->visible(fn (): bool => self::hasNoProducts()),
            ])
            ->columns([
                ImageColumn::make('image_url')
                    ->visibleFrom('md')
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
                    ->visibleFrom('md')
                    ->label('Unit price')
                    ->state(function (Product $record): string {
                        $unitPrice = $record->cheapestShop?->unitPrice();

                        if ($unitPrice === null) {
                            return '—';
                        }

                        return MoneyFormatter::format($unitPrice, $record->currency) . ' ' . $record->cheapestShop?->unitPriceLabel();
                    }),

                TextColumn::make('shops_count')
                    ->visibleFrom('md')
                    ->label('Shops')
                    ->counts('shops')
                    ->sortable(),

                IconColumn::make('active')
                    ->visibleFrom('md')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                // Actions have no visibleFrom(); the app theme scans this file, so
                // the utility classes compile.
                EditAction::make()->extraAttributes(['class' => 'hidden md:inline-flex']),
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

    private static function hasNoProducts(): bool
    {
        return ! Product::query()->where('user_id', auth()->id())->exists();
    }
}
