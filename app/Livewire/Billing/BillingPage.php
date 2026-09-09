<?php declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\PlanLimits;
use App\Billing\ProPrice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The customer's own billing screen: which plan they are on, how much of it
 * they are using, and the two buttons that matter — upgrade, and manage the
 * card in Stripe's portal.
 *
 * A port of the Filament page rather than a redesign: every rule below decides
 * whether money is offered or refused, so the logic moves unchanged and only
 * the rendering is new.
 */
class BillingPage extends Component
{
    public function render(): View
    {
        return view('livewire.billing.billing-page', [
            'plan' => $this->user()->plan(),
            'entitlements' => $this->entitlements(),
            'productCount' => $this->productCount(),
            'remainingProducts' => app(PlanLimits::class)->remainingProducts($this->user()),
            'periodEndsAt' => $this->periodEndsAt(),
            'isComped' => $this->user()->isComped(),
            'isCancelling' => $this->isCancelling(),
            'isOnTrial' => $this->isOnTrial(),
            'isBlocked' => $this->isBlocked(),
            'priceLabel' => ProPrice::label(),
            'trialDays' => ProPrice::trialDays(),
            'offersTrial' => $this->offersTrial(),
            'canUpgrade' => $this->canUpgrade(),
            'canManageBilling' => $this->canManageBilling(),
            'isPastDue' => $this->user()->isPastDue(),
            'isPro' => $this->user()->isPro(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function entitlements(): Entitlements
    {
        return $this->user()->entitlements();
    }

    private function productCount(): int
    {
        return Product::query()->where('user_id', $this->user()->id)->count();
    }

    /**
     * The renewal or expiry date to show. Null when there is nothing dated to
     * say — a free account with no history.
     */
    private function periodEndsAt(): ?string
    {
        $subscription = $this->user()->subscription(Plan::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            return null;
        }

        $date = $subscription->ends_at ?? $subscription->trial_ends_at;

        return $date?->timezone($this->user()->timezone ?: 'Europe/Amsterdam')->isoFormat('D MMMM YYYY');
    }

    private function isCancelling(): bool
    {
        return $this->user()->subscription(Plan::SUBSCRIPTION_TYPE)?->onGracePeriod() === true;
    }

    private function isOnTrial(): bool
    {
        return $this->user()->subscription(Plan::SUBSCRIPTION_TYPE)?->onTrial() === true;
    }

    private function isBlocked(): bool
    {
        return $this->user()->billing_blocked_at !== null;
    }

    /**
     * A former subscriber gets no second free fortnight, so the button must not
     * promise one — checkout would create a paid subscription instead.
     */
    private function offersTrial(): bool
    {
        return ProPrice::trialDays() > 0 && $this->user()->qualifiesForTrial();
    }

    private function canUpgrade(): bool
    {
        return BillingGate::isOpen() && ! $this->isBlocked();
    }

    /**
     * Anyone Stripe knows about can reach the portal, whatever their plan says.
     * A lost chargeback drops the account to Free while Stripe keeps billing the
     * subscription — hiding the portal there would leave someone paying with no
     * way to stop.
     */
    private function canManageBilling(): bool
    {
        return $this->user()->stripe_id !== null;
    }
}
