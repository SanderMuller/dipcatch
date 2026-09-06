<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Disputes\Pages;

use App\Filament\Admin\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\ListRecords;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;
}
