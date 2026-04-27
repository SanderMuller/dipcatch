<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

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
}
