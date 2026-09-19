<?php declare(strict_types=1);

namespace App\Concerns;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Billing state for a user. `plan()` is the only place that turns Stripe
 * subscription state into an entitlement, so trial, grace period and a lost
 * chargeback are all decided once.
 */
trait Subscribes
{
    /**
     * Pro as a gift rather than a payment.
     *
     * Blocked beats comped on purpose: an account blocked for a lost
     * chargeback does not get Pro back because someone comped it once. That
     * precedence lives here so no screen can re-derive it and disagree.
     */
    public function isComped(): bool
    {
        return $this->billing_blocked_at === null
            && $this->comped_until !== null
            && $this->comped_until->isFuture();
    }

    public function plan(): Plan
    {
        if ($this->billing_blocked_at !== null) {
            return Plan::Free;
        }

        if ($this->isComped()) {
            return Plan::Pro;
        }

        $subscription = $this->subscription(Plan::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            // A generic trial started before any subscription exists still
            // grants Pro — `onTrial()` with no arguments reads the user's
            // own `trial_ends_at`.
            return $this->onTrial() ? Plan::Pro : Plan::Free;
        }

        // `active()`, not `valid()`, because `ProUsers` — the scheduler's
        // reader — selects on Cashier's `active()` scope, and the instance
        // method is that same predicate. It is the predicate that matches,
        // not the answer: `plan()` reads the newest `pro` row while
        // `ProUsers` matches any active one, so two rows can still disagree.
        // `keepPastDueSubscriptionsActive()` in AppServiceProvider is what
        // keeps Pro through the dunning retries.
        return $subscription->active() ? Plan::Pro : Plan::Free;
    }

    public function isPro(): bool
    {
        return $this->plan()->isPro();
    }

    public function entitlements(): Entitlements
    {
        return Entitlements::for($this);
    }

    /**
     * A trial is for people who have never had this subscription. Cancelling
     * and re-subscribing must not hand out a second free fortnight.
     */
    public function qualifiesForTrial(): bool
    {
        return $this->subscriptions()
            ->where('type', Plan::SUBSCRIPTION_TYPE)
            ->doesntExist();
    }

    public function isPastDue(): bool
    {
        return $this->subscription(Plan::SUBSCRIPTION_TYPE)?->pastDue() === true;
    }

    /**
     * @return HasMany<StripeDispute, $this>
     */
    public function stripeDisputes(): HasMany
    {
        return $this->hasMany(StripeDispute::class);
    }

    /**
     * @return HasMany<StripePayment, $this>
     */
    public function stripePayments(): HasMany
    {
        return $this->hasMany(StripePayment::class);
    }
}
