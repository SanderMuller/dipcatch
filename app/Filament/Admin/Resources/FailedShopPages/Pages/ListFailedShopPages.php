<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\FailedShopPages\Pages;

use App\Filament\Admin\Resources\FailedShopPages\FailedShopPageResource;
use Filament\Resources\Pages\ListRecords;

final class ListFailedShopPages extends ListRecords
{
    protected static string $resource = FailedShopPageResource::class;
}
