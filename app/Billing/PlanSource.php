<?php declare(strict_types=1);

namespace App\Billing;

use App\Concerns\Subscribes;

/**
 * Which branch of {@see Subscribes::planSource()} decided an account's plan.
 * The admin screens label this rather than re-deriving it, so they cannot
 * name a branch the plan did not take.
 */
enum PlanSource
{
    /** Pro withdrawn after a lost chargeback. */
    case Blocked;
    /** Pro as a gift. */
    case Comp;
    /** A subscription row that grants Pro. */
    case Subscription;
    /** The account's own trial, before any subscription exists. */
    case AccountTrial;
    /** Nothing grants Pro. */
    case None;

    public function plan(): Plan
    {
        return match ($this) {
            self::Comp, self::Subscription, self::AccountTrial => Plan::Pro,
            self::Blocked, self::None => Plan::Free,
        };
    }
}
