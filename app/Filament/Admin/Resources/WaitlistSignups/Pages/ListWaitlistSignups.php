<?php

namespace App\Filament\Admin\Resources\WaitlistSignups\Pages;

use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWaitlistSignups extends ListRecords
{
    protected static string $resource = WaitlistSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
