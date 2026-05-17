<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\RelationManagers;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Support\MoneyFormatter;
use App\Support\UrlNormalizer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * Renders the "Shops" list on the product view page: each offer's host,
 * current price, in-stock state, health, and a remove action. The "Add another
 * shop" form sits above this table (see the Livewire `add-shop` component
 * embedded in `ProductInfolist`).
 */
class ShopsRelationManager extends RelationManager
{
    protected static string $relationship = 'shops';

    protected static ?string $title = 'Shops';

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->emptyStateHeading('No shops yet')
            ->emptyStateDescription('Use the form above to add the first shop URL.')
            ->columns([
                TextColumn::make('host')
                    ->label('Shop')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_price')
                    ->label('Price')
                    ->state(fn (Shop $record): string => MoneyFormatter::format(
                        $record->current_price === null ? null : (string) $record->current_price,
                        $record->currency,
                    ))
                    ->sortable(),

                IconColumn::make('current_in_stock')
                    ->label('In stock')
                    ->boolean(),

                TextColumn::make('health')
                    ->badge()
                    ->color(fn (ShopHealth $state): string => match ($state) {
                        ShopHealth::Ok => 'success',
                        ShopHealth::Failing => 'warning',
                        ShopHealth::Dead => 'danger',
                    }),

                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                IconColumn::make('active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('health')
                    ->options(ShopHealth::class),
            ])
            ->defaultSort('current_price', 'asc')
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->label('Open')
                    ->url(fn (Shop $record): string => $record->url)
                    ->openUrlInNewTab(),

                Action::make('edit_url')
                    ->label('Edit URL')
                    ->icon(Heroicon::PencilSquare)
                    ->modalHeading('Update shop URL')
                    ->modalSubmitActionLabel('Save and re-check')
                    ->fillForm(fn (Shop $record): array => ['url' => $record->url])
                    ->schema([
                        TextInput::make('url')
                            ->label('URL')
                            ->url()
                            ->required()
                            ->helperText('We will fetch the new page right now and update the price.'),
                    ])
                    ->action(function (array $data, Shop $record, RelationManager $livewire): void {
                        /** @var array<string, mixed> $data */
                        self::handleEditUrl($data, $record, $livewire);
                    }),

                Action::make('toggle_active')
                    ->label(fn (Shop $record): string => $record->active ? 'Pause' : 'Resume')
                    ->icon(fn (Shop $record) => $record->active ? Heroicon::Pause : Heroicon::Play)
                    ->color(fn (Shop $record): string => $record->active ? 'gray' : 'success')
                    ->action(function (Shop $record): void {
                        $wasInactive = ! $record->active;
                        $record->update(['active' => ! $record->active]);

                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return;
                        }

                        // Toggle-on: hand the latest successful check id to
                        // recompute so a returning cheaper offer registers as
                        // a real drop. Toggle-off raises cheapest at most.
                        $triggerId = $wasInactive
                            ? self::latestSuccessfulCheckId($record)
                            : null;

                        $product->recomputeCheapestShop($triggerId);
                    }),

                DeleteAction::make()
                    ->label('Remove shop')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove this shop?')
                    ->modalDescription('Stops tracking this shop for the product. Price history is retained.')
                    ->after(function (Shop $record): void {
                        $product = $record->product;
                        if ($product instanceof Product) {
                            $product->recomputeCheapestShop();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(function (EloquentCollection $records): void {
                            // Bulk delete cascades price_checks via FK; recompute
                            // each affected product so the denormalized
                            // `cheapest_*` columns reflect what's left.
                            $productIds = $records->pluck('product_id')->unique()->all();
                            Product::query()->whereIn('id', $productIds)->each(
                                fn (Product $product) => $product->recomputeCheapestShop(),
                            );
                        }),
                ]),
            ]);
    }

    /** Live re-render after the sibling AddShop component saves an offer. */
    #[On('shop-added')]
    public function refreshAfterOfferAdded(): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private static function handleEditUrl(array $data, Shop $record, RelationManager $livewire): void
    {
        $raw = is_string($data['url'] ?? null) ? trim($data['url']) : '';

        try {
            $normalized = UrlNormalizer::normalize($raw);
        } catch (InvalidArgumentException) {
            self::notify('That URL is not valid', danger: true);

            return;
        }

        if (UrlNormalizer::hash($normalized) === $record->url_hash) {
            self::notify('URL is already that — nothing to update');

            return;
        }

        $collision = Shop::query()
            ->where('product_id', $record->product_id)
            ->where('url_hash', UrlNormalizer::hash($normalized))
            ->whereKeyNot($record->id)
            ->exists();

        if ($collision) {
            self::notify('Another shop for this product already uses that URL', danger: true);

            return;
        }

        $record->updateUrl($normalized);

        // Run synchronously: the user is staring at a modal and expects the
        // new price now. Sync dispatch also bypasses `ShouldBeUnique`, so a
        // background recheck already holding the per-offer lock doesn't
        // swallow this run.
        CheckShopPrice::dispatchSync($record->refresh());

        $livewire->dispatch('shop-added');

        self::notify('Shop URL updated and price re-checked', success: true);
    }

    private static function notify(string $title, bool $success = false, bool $danger = false): void
    {
        $n = Notification::make()->title($title);
        if ($success) {
            $n->success();
        }
        if ($danger) {
            $n->danger();
        }
        $n->send();
    }

    private static function latestSuccessfulCheckId(Shop $shop): ?int
    {
        $id = PriceCheck::query()
            ->where('shop_id', $shop->id)
            ->where('status', ScrapeStatus::Ok->value)
            ->latest('checked_at')
            ->value('id');

        return is_scalar($id) ? (int) $id : null;
    }
}
