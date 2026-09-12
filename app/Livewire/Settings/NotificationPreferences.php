<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use App\Notifications\TestNotification;
use App\Support\IanaTimezones;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * How and when a person hears about a price drop.
 *
 * `notify_via_filament` keeps its name deliberately. It reads as
 * Filament-specific but is internal, the label users see already says "in-app",
 * and renaming it means a migration over a column holding live preferences.
 * See specs/flux-user-app-migration.md, Resolved Questions 6.
 */
class NotificationPreferences extends Component
{
    public bool $notify_via_email = false;

    public bool $notify_via_filament = false;

    public bool $notify_via_push = false;

    public string $default_currency = 'EUR';

    public string $timezone = 'Europe/Amsterdam';

    public function mount(): void
    {
        $user = $this->user();

        $this->notify_via_email = (bool) $user->notify_via_email;
        $this->notify_via_filament = (bool) $user->notify_via_filament;
        $this->notify_via_push = (bool) $user->notify_via_push;
        $this->default_currency = is_string($user->default_currency) && $user->default_currency !== ''
            ? $user->default_currency
            : 'EUR';
        $this->timezone = is_string($user->timezone) && $user->timezone !== ''
            ? $user->timezone
            : 'Europe/Amsterdam';
    }

    public function save(): void
    {
        $timezone = IanaTimezones::isValid($this->timezone) ? $this->timezone : 'Europe/Amsterdam';

        $this->user()->forceFill([
            'notify_via_email' => $this->notify_via_email,
            'notify_via_filament' => $this->notify_via_filament,
            'notify_via_push' => $this->notify_via_push,
            'default_currency' => $this->default_currency !== '' ? $this->default_currency : 'EUR',
            'timezone' => $timezone,
            // An explicit save is the strongest signal of intent, so stamp it:
            // the browser-detected timezone POST must never overwrite a choice
            // the user made deliberately.
            'timezone_detected_at' => now(),
        ])->save();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * Proves the channels actually deliver, which a saved toggle does not.
     */
    public function sendTest(): void
    {
        $this->user()->notify(new TestNotification());

        Flux::toast(variant: 'success', text: __('Test notification sent.'));
    }

    public function render(): View
    {
        return view('livewire.settings.notification-preferences', [
            'timezones' => IanaTimezones::options(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
