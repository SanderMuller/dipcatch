<?php declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Billing\BillingGate;
use App\Billing\BillingInterval;
use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\PlanLimits;
use App\Billing\ProPrice;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The customer's own billing screen: the plan, how much of it they use, what
 * Pro adds, and the way to Stripe's portal. The private rules below decide
 * whether money is offered or refused.
 */
final class BillingPage extends Component
{
    public function render(): View
    {
        return view('livewire.billing.billing-page', [
            'plan' => $this->user()->plan(),
            'entitlements' => $this->entitlements(),
            'free' => Entitlements::of(Plan::Free),
            'pro' => Entitlements::of(Plan::Pro),
            'productCount' => $this->productCount(),
            'remainingProducts' => app(PlanLimits::class)->remainingProducts($this->user()),
            'status' => $this->status(),
            'isBlocked' => $this->isBlocked(),
            'priceLabel' => ProPrice::label(),
            'yearlyLabel' => ProPrice::hasYearly() ? ProPrice::yearlyLabel() : null,
            'trialDays' => ProPrice::trialDays(),
            'offersTrial' => $this->offersTrial(),
            'canUpgrade' => $this->canUpgrade(),
            'canManageBilling' => $this->canManageBilling(),
            'isPastDue' => $this->user()->isPastDue(),
            'isPro' => $this->user()->isPro(),
            'autoCategoriesOn' => $this->user()->auto_categories,
            'shopChecksOn' => $this->user()->shop_checks,
            'aiAvailable' => TypeSafeClient::configured(),
        ]);
    }

    /** The one line under the plan name: what it costs, or why it costs nothing now. */
    private function status(): string
    {
        $free = Entitlements::of(Plan::Free);

        return match (true) {
            $this->user()->isComped() => __('Pro is on us, so there is nothing to pay'),
            $this->isOnTrial() => __('Trial ends :date', ['date' => $this->periodEndsAt()]),
            $this->isCancelling() => __('Cancelled. Pro runs until :date', ['date' => $this->periodEndsAt()]),
            $this->user()->isPro() && ProPrice::intervalOf($this->user()->payingSubscription()?->stripe_price) === BillingInterval::Yearly => __(':price per year', ['price' => ProPrice::yearlyLabel()]),
            $this->user()->isPro() => __(':price per month', ['price' => ProPrice::label()]),
            default => __('Free for :count products at up to :shops shops each', ['count' => $free->maxProducts(), 'shops' => $free->maxShopsPerProduct()]),
        };
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
        $subscription = $this->user()->payingSubscription();

        if ($subscription === null) {
            return null;
        }

        $date = $subscription->ends_at ?? $subscription->trial_ends_at;

        return $date?->timezone($this->user()->timezone ?: 'Europe/Amsterdam')->isoFormat('D MMMM YYYY');
    }

    /**
     * Both of these explain why the account has Pro, so both are gated on
     * having it. A subscription can sit in a grace period or carry a future
     * `trial_ends_at` while `plan()` says Free — an expired or never-paid row
     * does both — and the card would then print "Trial ends Friday" under the
     * Free plan name.
     */
    private function isCancelling(): bool
    {
        return $this->user()->isPro()
            && $this->user()->payingSubscription()?->onGracePeriod() === true;
    }

    private function isOnTrial(): bool
    {
        return $this->user()->isPro()
            && $this->user()->payingSubscription()?->onTrial() === true;
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
