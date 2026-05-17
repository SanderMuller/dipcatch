<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @return Action[]
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            // Share: only visible when not yet shared. Single click generates
            // an unguessable slug + surfaces the URL in the success notification.
            Action::make('share')
                ->label('Share publicly')
                ->icon(Heroicon::Share)
                ->color('gray')
                ->visible(fn (?Product $record): bool => $record !== null && ! $record->isPubliclyShared())
                ->requiresConfirmation()
                ->modalHeading('Share this product publicly?')
                ->modalDescription('Anyone with the generated link will see the product summary, price history, and shop list. Edit actions, private notes, and the add-shop form stay hidden. Existing chat / social previews may persist for some time after you later stop sharing.')
                ->modalSubmitActionLabel('Generate link')
                ->action(function (Product $record): void {
                    $record->update(['share_slug' => Str::random(32)]);
                    Notification::make()
                        ->title('Public link created')
                        ->body($record->publicShareUrl() ?? '')
                        ->success()
                        ->persistent()
                        ->send();
                }),

            // Rotate + stop: only visible once shared. Standalone (not in
            // an ActionGroup) so they're individually testable via the
            // Filament action test API.
            Action::make('rotate_share')
                ->label('Rotate public link')
                ->icon(Heroicon::ArrowPath)
                ->color('warning')
                ->visible(fn (?Product $record): bool => $record !== null && $record->isPubliclyShared())
                ->requiresConfirmation()
                ->modalHeading('Rotate the public link?')
                ->modalDescription('A new URL is generated and the previous one stops working immediately. Useful if you suspect the previous link leaked.')
                ->action(function (Product $record): void {
                    $record->update(['share_slug' => Str::random(32)]);
                    Notification::make()
                        ->title('Public link rotated')
                        ->body($record->publicShareUrl() ?? '')
                        ->success()
                        ->persistent()
                        ->send();
                }),

            Action::make('stop_share')
                ->label('Stop sharing')
                ->icon(Heroicon::NoSymbol)
                ->color('danger')
                ->visible(fn (?Product $record): bool => $record !== null && $record->isPubliclyShared())
                ->requiresConfirmation()
                ->modalHeading('Stop sharing this product?')
                ->modalDescription('The public link will 404. Existing chat / social previews may persist for some time outside our control.')
                ->action(function (Product $record): void {
                    $record->update(['share_slug' => null]);
                    Notification::make()->title('Public sharing stopped')->success()->send();
                }),
        ];
    }

    /**
     * @return list<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            PriceHistoryChart::class,
        ];
    }

    /**
     * Re-fetch the Product when the embedded AddShop Livewire component
     * persists a new offer. Without this the infolist keeps showing the
     * stale `cheapest_price` / `cheapest_shop_id` until the user reloads.
     */
    #[On('shop-added')]
    public function refreshRecordAfterOfferAdded(): void
    {
        if ($this->record instanceof Product) {
            $this->record->refresh();
        }
    }
}
