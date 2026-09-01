<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Shops\Tables;

use App\Enums\ShopHealth;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Favicon;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\HtmlString;

/**
 * Admin triage of all offers across users. Filterable by health, active flag,
 * and host. Bulk actions cover the two common ops: re-enable a batch (when a
 * shop comes back online), and force-mark dead (when a host pattern stops
 * working and we want to clear the recheck queue noise).
 */
class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('host')
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(Favicon::html($state)))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('product.title')->label('Product')->limit(50)->searchable(),
                TextColumn::make('product.user.email')->label('Owner')->searchable(),
                TextColumn::make('current_price')
                    ->state(fn (Shop $r): string => MoneyFormatter::format(
                        $r->current_price === null ? null : (string) $r->current_price,
                        $r->currency,
                    ))
                    ->sortable(),
                TextColumn::make('health')
                    ->badge()
                    ->color(fn (ShopHealth $state): string => match ($state) {
                        ShopHealth::Ok => 'success',
                        ShopHealth::Failing => 'warning',
                        ShopHealth::Dead => 'danger',
                    }),
                TextColumn::make('consecutive_failures')
                    ->label('Fails')
                    ->sortable(),
                TextColumn::make('consecutive_5xx_failures')
                    ->label('5xx')
                    ->sortable(),
                TextColumn::make('last_status')->label('Last status')->badge(),
                TextColumn::make('last_checked_at')->since()->placeholder('Never')->sortable(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('notes')
                    ->limit(60)
                    ->placeholder('—')
                    ->tooltip(fn (Shop $r): ?string => $r->notes),
            ])
            ->filters([
                SelectFilter::make('health')
                    ->options(ShopHealth::class),
                TernaryFilter::make('active'),
                SelectFilter::make('host')
                    ->options(fn (): array => Shop::query()
                        ->select('host')
                        ->distinct()
                        ->orderBy('host')
                        ->pluck('host', 'host')
                        ->all()),
            ])
            ->defaultSort('last_checked_at', 'desc')
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('reenable')
                        ->label('Re-enable')
                        ->icon(Heroicon::ArrowPath)
                        ->color('success')
                        ->action(function (EloquentCollection $records): void {
                            // Iterate individually so each product recompute
                            // anchors any resulting drop to the re-enabled
                            // offer's own latest successful check.
                            foreach ($records as $shop) {
                                if (! $shop instanceof Shop) {
                                    continue;
                                }
                                $shop->forceFill([
                                    'active' => true,
                                    'health' => ShopHealth::Ok->value,
                                    'consecutive_failures' => 0,
                                    'consecutive_5xx_failures' => 0,
                                ])->save();

                                $product = $shop->product;
                                if ($product instanceof Product) {
                                    $product->recomputeCheapestShop(
                                        self::latestSuccessfulCheckId($shop),
                                    );
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('mark_dead')
                        ->label('Mark dead')
                        ->icon(Heroicon::NoSymbol)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (EloquentCollection $records): void {
                            Shop::query()
                                ->whereIn('id', $records->modelKeys())
                                ->update([
                                    'active' => false,
                                    'health' => ShopHealth::Dead->value,
                                ]);

                            self::recomputeAffectedProducts($records);
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @param  EloquentCollection<int, Shop>  $shops
     */
    private static function recomputeAffectedProducts(EloquentCollection $shops): void
    {
        $productIds = $shops->pluck('product_id')->unique()->all();

        Product::query()->whereIn('id', $productIds)->each(
            fn (Product $product) => $product->recomputeCheapestShop(),
        );
    }

    private static function latestSuccessfulCheckId(Shop $shop): ?int
    {
        $check = PriceCheck::query()
            ->where('shop_id', $shop->id)
            ->where('status', 'ok')
            ->latest('checked_at')
            ->first();

        return $check === null ? null : (int) $check->id;
    }
}
