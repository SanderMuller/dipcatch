<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmptySearches\Pages;

use App\Filament\Admin\Resources\EmptySearches\EmptySearchResource;
use Filament\Resources\Pages\ListRecords;

final class ListEmptySearches extends ListRecords
{
    protected static string $resource = EmptySearchResource::class;
}
