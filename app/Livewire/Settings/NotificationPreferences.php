<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use App\Notifications\TestNotification;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How a person hears about a price drop.
 *
 * `notify_via_filament` keeps its name deliberately. It reads as
 * Filament-specific but is internal, the label users see already says "in-app",
 * and renaming it means a migration over a column holding live preferences.
 * See specs/flux-user-app-migration.md, Resolved Questions 6.
 */
#[Title('Notification settings')]
final class NotificationPreferences extends Component
{
    public bool $notify_via_email = false;

    public bool $notify_via_filament = false;

    public bool $notify_via_push = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->notify_via_email = (bool) $user->notify_via_email;
        $this->notify_via_filament = (bool) $user->notify_via_filament;
        $this->notify_via_push = (bool) $user->notify_via_push;
    }

    public function save(): void
    {
        $this->user()->forceFill([
            'notify_via_email' => $this->notify_via_email,
            'notify_via_filament' => $this->notify_via_filament,
            'notify_via_push' => $this->notify_via_push,
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
        return view('livewire.settings.notification-preferences');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
