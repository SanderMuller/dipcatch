<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Notifications\TestNotification;
use App\Support\Iso4217;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property array<string, mixed> $data
 */
class NotificationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Bell;

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notification preferences';

    protected static ?string $slug = 'settings/notifications';

    protected string $view = 'filament.app.pages.notification-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->data = [
            'notify_via_email' => (bool) $user->notify_via_email,
            'notify_via_filament' => (bool) $user->notify_via_filament,
            'notify_via_push' => (bool) $user->notify_via_push,
            'default_currency' => is_string($user->default_currency) ? $user->default_currency : 'EUR',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Channels')
                    ->description('Choose how you want price-drop alerts delivered.')
                    ->schema([
                        Toggle::make('notify_via_email')
                            ->label('Email notifications'),
                        Toggle::make('notify_via_filament')
                            ->label('In-app (bell) notifications'),
                        Toggle::make('notify_via_push')
                            ->label('Browser push notifications')
                            ->helperText('Requires granting permission in your browser. The toggle stays off if permission is denied.'),
                    ])
                    ->columns(1),

                Section::make('Defaults')
                    ->schema([
                        Select::make('default_currency')
                            ->label('Default currency')
                            ->options(Iso4217::options())
                            ->searchable()
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $user->forceFill([
            'notify_via_email' => (bool) ($this->data['notify_via_email'] ?? false),
            'notify_via_filament' => (bool) ($this->data['notify_via_filament'] ?? false),
            'notify_via_push' => (bool) ($this->data['notify_via_push'] ?? false),
            'default_currency' => is_string($this->data['default_currency'] ?? null)
                ? $this->data['default_currency']
                : 'EUR',
        ])->save();

        Notification::make()->title('Preferences saved')->success()->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save preferences')
                ->icon(Heroicon::Check)
                ->action(fn (): null => tap(null, fn (): null => $this->save())),

            Action::make('test')
                ->label('Send test notification')
                ->icon(Heroicon::PaperAirplane)
                ->color('gray')
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $user->notify(new TestNotification());

                    Notification::make()
                        ->title('Test notification dispatched')
                        ->body('Check the channels you have enabled.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
