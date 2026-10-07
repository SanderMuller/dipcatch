<?php declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Billing\BillingGate;
use App\Billing\ProPitch;
use App\Billing\ProPrice;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where every Pro hint in the app leads: what Pro adds, then one button to
 * checkout. An account that already has Pro, or one checkout would refuse,
 * goes to the billing page instead.
 */
#[Title('Pro')]
final class ProPage extends Component
{
    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->isPro() || $user->billing_blocked_at !== null || $user->payingSubscription()?->valid() === true) {
            $this->redirectRoute('app.billing', navigate: true);
        }
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $pitch = ProPitch::for($user);
        $offersTrial = $pitch?->offersTrial === true;

        return view('livewire.billing.pro-page', [
            'onSale' => BillingGate::isOpen(),
            'ctaLabel' => $offersTrial
                ? __('Start :days-day trial, then :price a month', ['days' => ProPrice::trialDays(), 'price' => ProPrice::label()])
                : __('Upgrade to Pro: :price a month', ['price' => ProPrice::label()]),
            'yearlyLabel' => ProPrice::hasYearly()
                ? ($offersTrial ? __('Or :price a year after the trial', ['price' => ProPrice::yearlyLabel()]) : __('Or :price a year', ['price' => ProPrice::yearlyLabel()]))
                : null,
            'offersTrial' => $offersTrial,
        ]);
    }
}
