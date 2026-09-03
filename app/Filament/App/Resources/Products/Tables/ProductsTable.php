<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Tables;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Shop;
use App\Support\MoneyFormatter;
use App\Support\PromotionLabel;
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
            // `shops` rides along for the best-value column, which reads
            // every shop's unit price — one query for the page instead of one
            // per row.
            ->modifyQueryUsing(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->with(['cheapestShop', 'shops']))
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
                    // Named the same way the best value is, so the two columns
                    // can be read against each other, with the deadline when
                    // the price is only good until a date.
                    ->description(fn (Product $record): ?string => self::shopNote($record->cheapestShop))
                    ->sortable(),

                TextColumn::make('cheapest_shop_unit_price')
                    ->visibleFrom('md')
                    ->label('Unit price')
                    ->state(fn (Product $record): string => self::unitPriceState($record->cheapestShop, $record)),

                TextColumn::make('best_value')
                    ->visibleFrom('md')
                    ->label('Best value')
                    ->state(fn (Product $record): string => self::unitPriceState($record->bestValueShop(), $record))
                    // Which shop it is, since it is often not the cheapest one.
                    ->description(fn (Product $record): ?string => self::shopNote($record->bestValueShop())),

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

    /**
     * Where a quoted price comes from, and how long it lasts — "lidl.nl ·
     * until 6 Sep". The deadline is left off when the shop states none.
     */
    private static function shopNote(?Shop $shop): ?string
    {
        if ($shop === null) {
            return null;
        }

        return implode(' · ', array_filter([$shop->host, PromotionLabel::short($shop)]));
    }

    /**
     * A shop's price per unit — "EUR 5.38 /kg" — or a dash when the shop
     * states no pack size.
     */
    private static function unitPriceState(?Shop $shop, Product $product): string
    {
        $unitPrice = $shop?->unitPrice();

        if ($unitPrice === null) {
            return '—';
        }

        return MoneyFormatter::format($unitPrice, $product->currency) . ' ' . $shop?->unitPriceLabel();
    }

    private static function hasNoProducts(): bool
    {
        return ! Product::query()->where('user_id', auth()->id())->exists();
    }
}
