<?php

namespace App\Filament\Admin\Resources\WaitlistSignups\Pages;

use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWaitlistSignup extends CreateRecord
{
    protected static string $resource = WaitlistSignupResource::class;
}
