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
use Illuminate\Contracts\View\View;
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
            $this->sharingAction(),
        ];
    }

    /**
     * Single "Sharing" entry point — opens one modal that shows the public URL
     * with a copy button (when shared) or a Generate button (when not), plus
     * Rotate / Stop buttons. All mutating buttons in the modal call the public
     * Livewire methods below; they do the atomic conditional UPDATE that makes
     * concurrent tabs safe.
     */
    private function sharingAction(): Action
    {
        return Action::make('sharing')
            ->label('Sharing')
            ->icon(Heroicon::Share)
            ->color('gray')
            ->modalHeading('Public sharing')
            ->modalDescription('Anyone with the link will see the product summary, price history, and shop list. Edit actions, private notes, and the add-shop form stay hidden.')
            ->modalContent(fn (): View => view('filament.partials.product-sharing-modal', [
                'product' => $this->record instanceof Product ? $this->record : null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function generateShareLink(): void
    {
        $record = $this->record;
        if (! $record instanceof Product) {
            return;
        }

        $newSlug = Str::random(32);
        // Atomic conditional UPDATE: only set the slug if the row is still
        // un-shared. Two tabs racing both committing would otherwise overwrite
        // each other's freshly generated slug.
        $updated = Product::query()
            ->whereKey($record->getKey())
            ->whereNull('share_slug')
            ->update(['share_slug' => $newSlug]);
        $record->refresh();

        if ($updated === 0) {
            Notification::make()
                ->title('Already shared in another tab')
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title('Public link created')->success()->send();
    }

    public function rotateShareLink(): void
    {
        $record = $this->record;
        if (! $record instanceof Product) {
            return;
        }

        $previousSlug = $record->share_slug;
        $newSlug = Str::random(32);
        // Conditional on the slug we saw, not just "still shared". A concurrent
        // stop+share in another tab would already have changed the slug —
        // rotating then would silently overwrite the freshly issued URL.
        $updated = Product::query()
            ->whereKey($record->getKey())
            ->where('share_slug', $previousSlug)
            ->update(['share_slug' => $newSlug]);
        $record->refresh();

        if ($updated === 0) {
            Notification::make()
                ->title('Link changed in another tab, not rotated')
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title('Public link rotated')->success()->send();
    }

    public function stopSharing(): void
    {
        $record = $this->record;
        if (! $record instanceof Product) {
            return;
        }

        $previousSlug = $record->share_slug;
        // Conditional on the slug we saw. If another tab already rotated to a
        // new slug, "stop" against the old one is a no-op rather than a revoke
        // of the freshly issued URL.
        $updated = Product::query()
            ->whereKey($record->getKey())
            ->where('share_slug', $previousSlug)
            ->update(['share_slug' => null]);
        $record->refresh();

        if ($updated === 0) {
            Notification::make()
                ->title('Link changed in another tab, not revoked')
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title('Public sharing stopped')->success()->send();
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
