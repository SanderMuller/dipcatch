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
        $this->leaveWhenAllCovered();
    }

    public function skip(string $idea): void
    {
        $this->mark($idea, TrackingIdeaMarkState::Skipped);
        $this->leaveWhenAllCovered();
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
        $this->moveFocusTo('[data-test="tracking-ideas-show"]');
    }

    public function show(): void
    {
        $this->user()->forceFill(['tracking_ideas_hidden_at' => null])->save();
        $this->moveFocusTo('[data-test="tracking-ideas-browse"]');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.dashboard.tracking-ideas', [
            'checklist' => TrackingIdeaChecklist::for($user),
            'hidden' => $user->tracking_ideas_hidden_at !== null,
        ]);
    }

    /** With the last idea covered, the strip goes: focus goes to the page heading. */
    private function leaveWhenAllCovered(): void
    {
        if (TrackingIdeaChecklist::for($this->user())->open === []) {
            $this->moveFocusTo('main h1');
        }
    }

    /**
     * The control that had focus leaves the page on the next render, and the
     * browser would drop focus to the top. Close the dialog first, as its own
     * close would hand focus back to a trigger that is gone.
     */
    private function moveFocusTo(string $selector): void
    {
        $this->js(<<<JS
            const focus = () => {
                const target = document.querySelector('{$selector}');

                if (target && ! target.hasAttribute('tabindex') && target.tabIndex < 0) {
                    target.setAttribute('tabindex', '-1');
                }

                target?.focus();
            };
            const dialog = document.querySelector('dialog[data-modal="tracking-ideas"]');

            if (dialog?.open) {
                dialog.addEventListener('close', () => setTimeout(focus), { once: true });
                dialog.close();
            } else {
                focus();
            }
            JS);
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
