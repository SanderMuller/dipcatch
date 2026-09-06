<?php declare(strict_types=1);

namespace App\Jobs;

use App\Billing\ChargeOwnerResolver;
use App\Models\StripeDispute;
use App\Models\User;
use App\Notifications\BillingIncidentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * A dispute names a charge, not a customer, so linking it to an account
 * needs one Stripe call — and that call can fail. Stripe does not promise
 * another event after a close, so nothing else would ever come back to it:
 * a lost chargeback would sit unlinked and the account would keep Pro.
 *
 * This retries the lookup on its own schedule and applies the block the
 * close event could not.
 */
class RelinkStripeDispute implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $disputeId) {}

    /**
     * Widening waits: a Stripe blip clears in seconds, an outage in hours.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3_600, 21_600];
    }

    public function handle(ChargeOwnerResolver $owners): void
    {
        $dispute = StripeDispute::query()->find($this->disputeId);

        if ($dispute === null || $dispute->user_id !== null) {
            return;
        }

        $owner = $owners->forCharge($dispute->stripe_charge_id);

        if ($owner === null) {
            // Out of attempts is a real outcome: the queue's failure handling
            // surfaces it, and the dispute still shows unlinked in the admin
            // queue for a person to sort out.
            $this->release();

            return;
        }

        $dispute->forceFill(['user_id' => $owner->id])->save();
        $dispute->setRelation('user', $owner);

        if ($dispute->isLost()) {
            $owner->forceFill(['billing_blocked_at' => now()])->save();
        }

        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, BillingIncidentNotification::disputeClosed($dispute));
        }
    }
}
