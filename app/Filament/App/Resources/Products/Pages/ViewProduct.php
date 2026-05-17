<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @return EditAction[]
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
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
