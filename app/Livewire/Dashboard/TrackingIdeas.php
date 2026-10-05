<?php declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\TrackingIdea;
use App\Enums\TrackingIdeaMarkState;
use App\Models\User;
use App\Support\TrackingIdeaChecklist;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * A getting-started checklist of things people buy again and again: a strip
 * on the dashboard, with the list in a dialog, until every idea is covered.
 * Hiding the strip leaves a link to show it again.
 */
final class TrackingIdeas extends Component
{
    public function markDone(string $idea): void
    {
        $this->mark($idea, TrackingIdeaMarkState::Done);
    }

    public function skip(string $idea): void
    {
        $this->mark($idea, TrackingIdeaMarkState::Skipped);
    }

    public function undo(string $idea): void
    {
        $known = TrackingIdea::tryFrom($idea);

        if ($known !== null) {
            $this->user()->trackingIdeaMarks()->where('idea', $known->value)->delete();
        }
    }

    public function hide(): void
    {
        $this->user()->forceFill(['tracking_ideas_hidden_at' => now()])->save();
    }

    public function show(): void
    {
        $this->user()->forceFill(['tracking_ideas_hidden_at' => null])->save();
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.dashboard.tracking-ideas', [
            'checklist' => TrackingIdeaChecklist::for($user),
            'hidden' => $user->tracking_ideas_hidden_at !== null,
        ]);
    }

    private function mark(string $idea, TrackingIdeaMarkState $state): void
    {
        $known = TrackingIdea::tryFrom($idea);

        if ($known === null) {
            return;
        }

        $this->user()->trackingIdeaMarks()->updateOrCreate(
            ['idea' => $known->value],
            ['state' => $state, 'marked_at' => now()],
        );
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
