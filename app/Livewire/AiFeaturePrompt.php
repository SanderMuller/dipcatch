<?php declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Users\SwitchOnAiFeature;
use App\Enums\AiPromptPlace;
use App\Models\User;
use App\Support\AiPromptsOnPage;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Offers one AI feature at a place where the person does its work by hand.
 * Nothing is switched on without the click. "Not now" hides only this place,
 * see AiPromptPlace. A page offers each feature once: the first prompt to
 * mount in a request claims it (AiPromptsOnPage), and the view's x-init
 * hides one from a later request.
 */
final class AiFeaturePrompt extends Component
{
    #[Locked]
    public string $place;

    public bool $switchedOn = false;

    /** Not shown: not offered here, claimed by another prompt, dismissed, or switched on elsewhere. */
    public bool $hidden = false;

    /** Leaves a gap below the prompt, for a spot with nothing to space it. */
    #[Locked]
    public bool $spaced = false;

    /** Leaves a gap above the prompt, under the content it follows. */
    #[Locked]
    public bool $below = false;

    public function mount(string $place, bool $spaced = false, bool $below = false): void
    {
        $place = AiPromptPlace::from($place);
        $this->place = $place->value;
        $this->spaced = $spaced;
        $this->below = $below;
        $this->hidden = ! $place->feature()->isOfferedTo($this->user(), $place) || ! app(AiPromptsOnPage::class)->claim($place->feature());
    }

    public function switchOn(SwitchOnAiFeature $switchOn): void
    {
        $feature = AiPromptPlace::from($this->place)->feature();
        $user = $this->user();

        // Not the full offer rule: a click after the last product got a
        // category, or after "Not now" in another tab, still means yes.
        if (! $feature->isAvailableTo($user)) {
            return;
        }

        $switchOn($user, $feature);
        $this->switchedOn = true;
        $this->dispatch('ai-feature-switched-on', feature: $feature->value);
    }

    #[On('ai-feature-switched-on')]
    public function switchedOnElsewhere(string $feature): void
    {
        if ($feature === AiPromptPlace::from($this->place)->feature()->value) {
            $this->hidden = true;
        }
    }

    public function dismiss(): void
    {
        AiPromptPlace::from($this->place)->dismissFor($this->user());
        $this->hidden = true;
    }

    public function render(): View
    {
        $place = AiPromptPlace::from($this->place);

        return view('livewire.ai-feature-prompt', [
            'aiPlace' => $place,
            'aiFeature' => $place->feature(),
            'offered' => ! $this->switchedOn && ! $this->hidden,
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
