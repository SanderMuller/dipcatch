<?php declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Users\SwitchOnAiFeature;
use App\Enums\AiFeature;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Asks a Pro account once whether to switch on the AI features its plan
 * includes and it has off. On every app page, so it reaches each way into
 * Pro: a checkout, a trial, Pro given by an admin, and the accounts that
 * had Pro before it existed. Opening it stamps `ai_offer_shown_at`.
 */
final class ProAiOffer extends Component
{
    public bool $open = false;

    /** @var list<string> */
    #[Locked]
    public array $offered = [];

    /** @var list<string> */
    public array $chosen = [];

    public function mount(): void
    {
        $this->openIfDue();
    }

    /** On mount, and when the billing page sees the account turn Pro. */
    #[On('pro-started')]
    public function openIfDue(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        // Cheapest first: most page views stop at the stamp.
        if ($user->ai_offer_shown_at !== null || ! TypeSafeClient::configured()) {
            return;
        }

        $offered = array_values(array_filter(AiFeature::cases(), static fn (AiFeature $feature): bool => ! $feature->isOn($user) && $feature->planAllows($user)));

        if ($offered === []) {
            return;
        }

        $user->forceFill(['ai_offer_shown_at' => now()])->save();
        $this->offered = array_map(static fn (AiFeature $feature): string => $feature->value, $offered);
        $this->chosen = $this->offered;
        $this->open = true;
    }

    public function switchOn(SwitchOnAiFeature $switchOn): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $switched = 0;

        foreach (array_intersect(array_filter($this->chosen, is_string(...)), $this->offered) as $value) {
            $feature = AiFeature::from($value);

            if ($feature->isAvailableTo($user)) {
                $switchOn($user, $feature);
                $this->dispatch('ai-feature-switched-on', feature: $feature->value);
                $switched++;
            }
        }

        $this->open = false;

        if ($switched > 0) {
            Flux::toast(variant: 'success', text: __('Switched on. Change it any time in Settings, under Product features.'));
        }
    }

    public function render(): View
    {
        return view('livewire.pro-ai-offer', [
            'features' => array_map(AiFeature::from(...), $this->offered),
        ]);
    }
}
