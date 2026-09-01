<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Notifications\TestNotification;
use App\Rules\IanaTimezone;
use App\Support\IanaTimezones;
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
            'timezone' => is_string($user->timezone) && $user->timezone !== ''
                ? $user->timezone
                : 'Europe/Amsterdam',
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
                            ->label('Daily email digest')
                            ->helperText('One email per day at 09:00 in your local timezone, grouped by product. DipCatch no longer sends an email per drop.'),
                        Toggle::make('notify_via_filament')
                            ->label('In-app (bell) notifications')
                            ->helperText('Sent right away, one entry per drop.'),
                        Toggle::make('notify_via_push')
                            ->label('Browser push notifications')
                            ->helperText('Sent right away. Your browser must grant permission first. The toggle stays off if you deny it.'),
                    ])
                    ->columns(1),

                Section::make('Defaults')
                    ->schema([
                        Select::make('default_currency')
                            ->label('Default currency')
                            ->options(Iso4217::options())
                            ->searchable()
                            ->required(),
                        Select::make('timezone')
                            ->label('Timezone')
                            ->helperText('Sets when the 09:00 digest arrives. Pick the timezone you want it in.')
                            ->options(IanaTimezones::options())
                            ->searchable()
                            ->required()
                            ->rules([new IanaTimezone()]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $timezoneInput = $this->data['timezone'] ?? null;
        $timezone = is_string($timezoneInput) && IanaTimezones::isValid($timezoneInput)
            ? $timezoneInput
            : 'Europe/Amsterdam';

        $user->forceFill([
            'notify_via_email' => (bool) ($this->data['notify_via_email'] ?? false),
            'notify_via_filament' => (bool) ($this->data['notify_via_filament'] ?? false),
            'notify_via_push' => (bool) ($this->data['notify_via_push'] ?? false),
            'default_currency' => is_string($this->data['default_currency'] ?? null)
                ? $this->data['default_currency']
                : 'EUR',
            'timezone' => $timezone,
            // Explicit save is the strongest signal of intent — stamp so the
            // browser-detected timezone POST (see AutoDetectTimezoneController)
            // never overwrites the user's choice. Idempotent: re-saving on a
            // subsequent visit just refreshes the timestamp.
            'timezone_detected_at' => now(),
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
