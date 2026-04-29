<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * @return CreateAction[]
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
