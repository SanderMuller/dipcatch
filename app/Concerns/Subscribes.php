<?php declare(strict_types=1);

namespace App\Concerns;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Laravel\Cashier\Subscription;

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

        $subscriptions = $this->proSubscriptions();

        if ($subscriptions->isEmpty()) {
            // A generic trial started before any subscription exists still
            // grants Pro — `onTrial()` with no arguments reads the user's
            // own `trial_ends_at`.
            return $this->onTrial() ? Plan::Pro : Plan::Free;
        }

        // `active()`, not `valid()`, because `ProUsers` — the scheduler's
        // reader — selects on Cashier's `active()` scope, and the instance
        // method is that same predicate.
        //
        // Any row, not the newest one. `subscription()` returns the newest of
        // a type, so an abandoned checkout writing a later `incomplete` row
        // hid a live subscription underneath it: `plan()` said Free while
        // `ProUsers` said Pro and Stripe went on charging. The customer lost
        // their history window, their unit-price alerts and their recheck
        // cadence, permanently, because nothing ages an `incomplete` row out.
        //
        // `keepPastDueSubscriptionsActive()` in AppServiceProvider is what
        // keeps Pro through the dunning retries.
        return $subscriptions->contains(static fn (Subscription $subscription): bool => $subscription->active())
            ? Plan::Pro
            : Plan::Free;
    }

    /**
     * Every `pro` row this account holds, newest first.
     *
     * Cashier's `subscription()` answers with one of these, and which one it
     * picks is an accident of creation order rather than of entitlement.
     *
     * @return Collection<int, Subscription>
     */
    public function proSubscriptions(): Collection
    {
        return collect($this->subscriptions->all())
            ->filter(static fn (mixed $row): bool => $row instanceof Subscription && $row->type === Plan::SUBSCRIPTION_TYPE)
            ->values();
    }

    /**
     * The `pro` row that decides what the customer is being sold, if any.
     *
     * A live row wins over a dead one whatever their order, so a stray
     * `incomplete` cannot make the billing page offer a second subscription
     * to somebody Stripe already bills. The row that grants Pro comes first:
     * `valid()` also holds for an `incomplete` row inside its trial or grace
     * dates, which grants nothing.
     */
    public function payingSubscription(): ?Subscription
    {
        $rows = $this->proSubscriptions();

        return $rows->first(static fn (Subscription $subscription): bool => $subscription->active())
            ?? $rows->first(static fn (Subscription $subscription): bool => $subscription->valid())
            ?? $rows->first();
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
        return $this->payingSubscription()?->pastDue() === true;
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
