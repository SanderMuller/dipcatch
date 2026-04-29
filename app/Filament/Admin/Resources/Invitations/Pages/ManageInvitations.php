<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInvitations extends ManageRecords
{
    protected static string $resource = InvitationResource::class;

    /**
     * @return CreateAction[]
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): Invitation {
                    /** @var array{email: string} $data */
                    return InvitationResource::createInvitationFor(
                        email: $data['email'],
                        invitedById: (int) auth()->id(),
                    );
                }),
        ];
    }
}
