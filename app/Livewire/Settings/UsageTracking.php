<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Analytics\Actions\UnlinkPageViews;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The objection (GDPR Art. 21) to linking page views to the account, on the
 * profile page. Switching it off also unlinks the views already stored.
 */
final class UsageTracking extends Component
{
    public bool $usage_tracking = true;

    public function mount(): void
    {
        $this->usage_tracking = $this->user()->allowsUsageTracking();
    }

    public function save(UnlinkPageViews $unlink): void
    {
        $user = $this->user();

        $user->forceFill(['usage_tracking_opted_out' => ! $this->usage_tracking])->save();

        if (! $this->usage_tracking) {
            $unlink($user);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.usage-tracking');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
