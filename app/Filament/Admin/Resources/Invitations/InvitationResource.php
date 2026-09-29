<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Invitations;

use App\Filament\Admin\Resources\Invitations\Pages\ManageInvitations;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

final class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->email()
                ->required()
                ->rule(fn (): Closure => self::rejectTakenAddress(...)),
        ]);
    }

    /**
     * Reject an address with a pending invitation or an account — otherwise
     * the redeem flow would 422 on `users.email` and leave a permanently-broken
     * invitation row behind. Lower-cased first: both are stored that way, and
     * a `unique` rule compares byte for byte.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    private static function rejectTakenAddress(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $email = Str::lower($value);

        if (Invitation::query()->where('email', $email)->whereNull('redeemed_at')->exists()
            || User::query()->where('email', $email)->exists()) {
            $fail('validation.unique')->translate();
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('inviter.name')->label('Invited by')->toggleable(),
                TextColumn::make('expires_at')->dateTime()->sortable(),
                TextColumn::make('redeemed_at')->dateTime()->placeholder('Pending')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return PageRegistration[]
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageInvitations::route('/'),
        ];
    }

    public static function createInvitationFor(string $email, int $invitedById): Invitation
    {
        $invitation = Invitation::query()->create([
            'email' => $email,
            'token' => Str::random(64),
            'invited_by' => $invitedById,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->send(new InvitationMail($invitation));

        return $invitation;
    }
}
