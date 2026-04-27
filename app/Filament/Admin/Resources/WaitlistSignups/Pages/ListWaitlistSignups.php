<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\WaitlistSignups\Pages;

use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use App\Filament\Exports\WaitlistSignupExporter;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListWaitlistSignups extends ListRecords
{
    protected static string $resource = WaitlistSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()->exporter(WaitlistSignupExporter::class),
        ];
    }
}
