<?php declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AiFeature;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Offers one AI feature where it helps, with a button that switches it on.
 * Nothing is switched on without that click. "Not now" hides every AI prompt
 * for the account; the settings page still has both switches.
 */
final class AiFeaturePrompt extends Component
{
    #[Locked]
    public string $feature;

    public bool $switchedOn = false;

    /** Leaves a gap below the prompt, for a spot with nothing to space it. */
    #[Locked]
    public bool $spaced = false;

    public function mount(string $feature, bool $spaced = false): void
    {
        $this->feature = AiFeature::from($feature)->value;
        $this->spaced = $spaced;
    }

    public function switchOn(): void
    {
        $feature = AiFeature::from($this->feature);
        $user = $this->user();

        // Not the full offer rule: a click after the last product got a
        // category, or after "Not now" in another tab, still means yes.
        if (! $feature->isAvailableTo($user)) {
            return;
        }

        $user->forceFill([$feature->column() => true])->save();
        $this->switchedOn = true;
    }

    public function dismiss(): void
    {
        $this->user()->forceFill(['ai_prompts_dismissed_at' => now()])->save();
    }

    public function render(): View
    {
        $feature = AiFeature::from($this->feature);

        return view('livewire.ai-feature-prompt', [
            'aiFeature' => $feature,
            'offered' => ! $this->switchedOn && $feature->isOfferedTo($this->user()),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
