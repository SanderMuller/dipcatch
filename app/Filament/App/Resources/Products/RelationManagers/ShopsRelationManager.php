<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\RelationManagers;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Favicon;
use App\Support\MoneyFormatter;
use App\Support\UrlNormalizer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * Renders the "Shops" list on the product view page: each offer's host,
 * current price, in-stock state, health, and a remove action. The "Add a
 * shop" button in the table header opens a modal hosting the Livewire
 * `add-shop` component.
 */
class ShopsRelationManager extends RelationManager
{
    protected static string $relationship = 'shops';

    protected static ?string $title = 'Shops';

    private const Heroicon NOTES_ICON = Heroicon::ChatBubbleOvalLeft;

    /**
     * Relation managers on View pages are read-only by default, which
     * silently hides the delete (Remove shop) action — this table is the
     * product's primary management surface, so keep it writable.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->header(fn (RelationManager $livewire): View => view('filament.partials.add-shop-header', [
                'product' => $livewire->getOwnerRecord(),
            ]))
            ->emptyStateHeading('No shops yet')
            ->emptyStateDescription('Paste a product URL from any webshop to start tracking its price.')
            ->emptyStateActions([])
            ->columns([
                ImageColumn::make('image_url')
                    ->visibleFrom('md')
                    ->label('')
                    ->state(fn (Shop $record): ?string => $record->safeImageUrl())
                    ->circular()
                    ->imageSize(44),

                TextColumn::make('host')
                    ->label('Shop')
                    ->formatStateUsing(fn (Shop $record): HtmlString => new HtmlString(Favicon::html($record->host)))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('notes_indicator')
                    ->visibleFrom('md')
                    ->label('')
                    ->state(fn (Shop $record): bool => self::hasNotes($record))
                    ->icon(fn (Shop $record): ?Heroicon => self::hasNotes($record) ? self::NOTES_ICON : null)
                    ->color('gray')
                    // Mounts the registered `edit_notes` record action (only
                    // the name is used), so the icon opens the same modal.
                    ->action(Action::make('edit_notes'))
                    ->disabledClick(fn (Shop $record): bool => ! self::hasNotes($record))
                    ->tooltip(fn (Shop $record): ?string => self::hasNotes($record)
                        ? Str::limit((string) $record->notes, 120)
                        : null),

                TextColumn::make('current_price')
                    ->label('Price')
                    ->visibleFrom('md')
                    ->state(fn (Shop $record): string => self::priceState($record))
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('Unit price')
                    ->visibleFrom('md')
                    ->state(fn (Shop $record): string => self::unitPriceState($record)),

                // Below md the two money columns collapse into one so the
                // price and unit price stay visible without a horizontal scroll.
                TextColumn::make('price_compact')
                    ->label('Price')
                    ->hiddenFrom('md')
                    ->state(fn (Shop $record): string => self::priceState($record))
                    ->description(fn (Shop $record): ?string => $record->unitPrice() === null ? null : self::unitPriceState($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('current_price', $direction === 'desc' ? 'desc' : 'asc')),

                IconColumn::make('current_in_stock')
                    ->visibleFrom('md')
                    ->label('In stock')
                    ->boolean(),

                TextColumn::make('health')
                    ->visibleFrom('md')
                    ->badge()
                    ->color(fn (ShopHealth $state): string => match ($state) {
                        ShopHealth::Ok => 'success',
                        ShopHealth::Failing => 'warning',
                        ShopHealth::Dead => 'danger',
                    }),

                TextColumn::make('last_checked_at')
                    ->visibleFrom('md')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

            ])
            // A paused (inactive) shop reads as dimmed instead of carrying
            // its own boolean column.
            ->recordClasses(fn (Shop $record): ?string => $record->active ? null : 'opacity-50')
            ->filters([
                SelectFilter::make('health')
                    ->options(ShopHealth::class),
            ])
            ->defaultSort('current_price')
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->label('Open')
                    // Icon only below md so the row fits a phone (label stays sr-only).
                    ->extraAttributes(['class' => 'max-md:[&_.fi-link-label]:sr-only'])
                    ->url(fn (Shop $record): string => $record->url)
                    ->openUrlInNewTab(),

                ActionGroup::make([
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
                                ->helperText('We fetch the new page now and update the price.'),
                        ])
                        ->action(function (array $data, Shop $record, RelationManager $livewire): void {
                            /** @var array<string, mixed> $data */
                            self::handleEditUrl($data, $record, $livewire);
                        }),

                    Action::make('edit_notes')
                        ->label('Notes')
                        ->icon(self::NOTES_ICON)
                        ->modalHeading('Shop notes (private)')
                        ->modalSubmitActionLabel('Save notes')
                        ->fillForm(fn (Shop $record): array => ['notes' => $record->notes])
                        ->schema([
                            Textarea::make('notes')
                                ->label('Notes (private)')
                                ->placeholder('Anything worth remembering about this shop: shipping limits, coupons, payment quirks.')
                                ->rows(4)
                                ->maxLength(64000)
                                ->columnSpanFull(),
                        ])
                        ->action(function (array $data, Shop $record): void {
                            $raw = $data['notes'] ?? null;
                            $notes = is_string($raw) ? trim($raw) : '';
                            $record->update(['notes' => $notes === '' ? null : $notes]);
                            self::notify('Notes saved', success: true);
                        }),

                    Action::make('toggle_active')
                        ->label(fn (Shop $record): string => $record->active ? 'Pause' : 'Resume')
                        ->icon(fn (Shop $record): Heroicon => $record->active ? Heroicon::Pause : Heroicon::Play)
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
                        ->after(function (Shop $record, RelationManager $livewire): void {
                            $product = $record->product;
                            if ($product instanceof Product) {
                                $product->recomputeCheapestShop();
                            }

                            // The chain is trackable again, so the suggestions
                            // panel may now have a row it was hiding.
                            $livewire->dispatch('shop-removed');
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(function (EloquentCollection $records, RelationManager $livewire): void {
                            // Bulk delete cascades price_checks via FK; recompute
                            // each affected product so the denormalized
                            // `cheapest_*` columns reflect what's left.
                            $productIds = $records->pluck('product_id')->unique()->all();
                            Product::query()->whereIn('id', $productIds)->each(
                                fn (Product $product) => $product->recomputeCheapestShop(),
                            );

                            $livewire->dispatch('shop-removed');
                        }),
                ]),
            ]);
    }

    /** Live re-render after the AddShop component saves an offer. */
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
            self::notify('That URL is already saved. Nothing to update.');

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
        dispatch_sync(new CheckShopPrice($record->refresh()));

        $livewire->dispatch('shop-added');

        self::notify('Shop URL updated and price re-checked', success: true);
    }

    private static function priceState(Shop $record): string
    {
        return MoneyFormatter::format(
            $record->current_price === null ? null : (string) $record->current_price,
            $record->currency,
        );
    }

    private static function unitPriceState(Shop $record): string
    {
        $unitPrice = $record->unitPrice();

        if ($unitPrice === null) {
            return '—';
        }

        return MoneyFormatter::format($unitPrice, $record->currency) . ' ' . $record->unitPriceLabel();
    }

    private static function hasNotes(Shop $shop): bool
    {
        return $shop->notes !== null && $shop->notes !== '';
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
