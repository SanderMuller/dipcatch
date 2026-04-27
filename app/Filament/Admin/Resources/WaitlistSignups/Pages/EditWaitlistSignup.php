<?php

namespace App\Filament\Admin\Resources\WaitlistSignups\Pages;

use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWaitlistSignup extends EditRecord
{
    protected static string $resource = WaitlistSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
