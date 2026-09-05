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
    public function plan(): Plan
    {
        if ($this->billing_blocked_at !== null) {
            return Plan::Free;
        }

        $subscription = $this->subscription(Plan::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            // A generic trial started before any subscription exists still
            // grants Pro — `onTrial()` with no arguments reads the user's
            // own `trial_ends_at`.
            return $this->onTrial() ? Plan::Pro : Plan::Free;
        }

        // `valid()` covers active, trialing and the cancelled-but-not-yet-
        // expired grace period. It excludes past due once the retries run out.
        return $subscription->valid() ? Plan::Pro : Plan::Free;
    }

    public function isPro(): bool
    {
        return $this->plan()->isPro();
    }

    public function entitlements(): Entitlements
    {
        return Entitlements::for($this);
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
