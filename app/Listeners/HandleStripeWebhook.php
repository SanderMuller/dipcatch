<?php declare(strict_types=1);

namespace App\Listeners;

use App\Billing\ChargeOwnerResolver;
use App\Jobs\RelinkStripeDispute;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\BillingIncidentNotification;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * The events Cashier's own controller does not act on. Cashier keeps the
 * subscription rows in step; this listener mirrors money and disputes, and
 * raises the alerts.
 *
 * Every branch is idempotent — Stripe redelivers, and a replay must not
 * record the same money twice or alert twice.
 */
class HandleStripeWebhook
{
    /**
     * The events Cashier's controller leaves alone.
     */
    private const array HANDLED = [
        'customer.subscription.created',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
        'charge.dispute.closed',
    ];

    public function __construct(private readonly ChargeOwnerResolver $owners) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        if (! in_array($type, self::HANDLED, true)) {
            return;
        }

        // The claim and the work share one transaction: a delivery that
        // fails halfway rolls back its own claim, so Stripe's retry runs the
        // whole thing again instead of finding it already marked done.
        StripeWebhookEvent::handleOnce(
            $this->string($payload, 'id'),
            $type,
            fn () => $this->dispatch($type, $object),
        );
    }

    /**
     * @param  array<mixed, mixed>  $object
     */
    private function dispatch(string $type, array $object): void
    {
        match ($type) {
            'customer.subscription.created' => $this->subscriptionStarted($object),
            'invoice.payment_succeeded' => $this->recordPayment($object),
            'invoice.payment_failed' => $this->paymentFailed($object),
            'charge.refunded' => $this->recordRefund($object),
            'charge.dispute.created' => $this->disputeOpened($object),
            'charge.dispute.closed' => $this->disputeClosed($object),
            default => null,
        };
    }

    /**
     * The checkout is over, so the session it was started from is no longer
     * pending — the next upgrade attempt must not resume it.
     *
     * @param  array<mixed, mixed>  $subscription
     */
    private function subscriptionStarted(array $subscription): void
    {
        $this->owners->forCustomer($this->string($subscription, 'customer'))
            ?->forceFill(['stripe_checkout_session_id' => null])
            ->save();
    }

    /**
     * @param  array<mixed, mixed>  $invoice
     */
    private function recordPayment(array $invoice): void
    {
        $amount = $this->int($invoice, 'amount_paid');

        if ($amount <= 0) {
            return;
        }

        $this->storeMoney(
            id: $this->string($invoice, 'id'),
            kind: StripePayment::KIND_PAYMENT,
            amount: $amount,
            currency: $this->string($invoice, 'currency'),
            user: $this->owners->forCustomer($this->string($invoice, 'customer')),
            // An invoice can settle days after it was raised.
            at: $this->paidAt($invoice),
        );
    }

    /**
     * @param  array<mixed, mixed>  $charge
     */
    private function recordRefund(array $charge): void
    {
        $amount = $this->int($charge, 'amount_refunded');

        if ($amount <= 0) {
            return;
        }

        $user = $this->owners->forCustomer($this->string($charge, 'customer'));

        // Stripe reports the running total refunded on the charge, not the
        // single refund, and sends the event again for each partial refund.
        // Storing the total keeps the sum right; only a rise is news.
        $rise = $this->storeMoney(
            id: $this->string($charge, 'id'),
            kind: StripePayment::KIND_REFUND,
            // Negative so a sum over the table is net revenue.
            amount: -$amount,
            currency: $this->string($charge, 'currency'),
            user: $user,
            // A refund can follow its charge by months.
            at: $this->refundedAt($charge),
        );

        if ($rise > 0) {
            $this->alertOwners(BillingIncidentNotification::refund($rise, $this->string($charge, 'currency'), $user));
        }
    }

    /**
     * @param  array<mixed, mixed>  $dispute
     */
    private function disputeOpened(array $dispute): void
    {
        $record = $this->upsertDispute($dispute);

        if ($record === null || ! $record->wasRecentlyCreated) {
            // The close already arrived and created the row. Nothing about
            // this dispute is news any more.
            return;
        }

        // Pro stays until the dispute is lost — a bank can flag an honest
        // customer's payment, and taking the product away mid-dispute
        // punishes them for it.
        $this->alertOwners(BillingIncidentNotification::disputeOpened($record));
    }

    /**
     * @param  array<mixed, mixed>  $dispute
     */
    private function disputeClosed(array $dispute): void
    {
        // Creates the row when the close arrives first: Stripe does not
        // promise order, and a lost chargeback must still block the account.
        $record = $this->upsertDispute($dispute);

        if ($record === null || ! $record->isOpen()) {
            return;
        }

        $record->forceFill([
            'status' => $this->string($dispute, 'status'),
            'closed_at' => now(),
        ])->save();

        $record->user?->forceFill([
            'billing_blocked_at' => $this->blockUntil($record),
        ])->save();

        if ($record->user_id === null) {
            // Nothing else will revisit this: Stripe promises no further
            // event after a close, and this event id is already claimed.
            RelinkStripeDispute::dispatch($record->id);
        }

        $this->alertOwners(BillingIncidentNotification::disputeClosed($record));
    }

    /**
     * The dispute row for this payload, created if it is not there yet.
     *
     * An owner lookup needs one Stripe call and can fail. A row left
     * unlinked is retried on the next event about the same dispute, so a
     * momentary Stripe outage does not cost the account its block.
     *
     * @param  array<mixed, mixed>  $dispute
     */
    private function upsertDispute(array $dispute): ?StripeDispute
    {
        $stripeId = $this->string($dispute, 'id');

        if ($stripeId === '') {
            return null;
        }

        $charge = $this->string($dispute, 'charge');

        $record = StripeDispute::query()->firstOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $this->owners->forCharge($charge)?->id,
                'stripe_charge_id' => $charge,
                'amount' => $this->int($dispute, 'amount'),
                'currency' => $this->string($dispute, 'currency'),
                'reason' => $this->string($dispute, 'reason'),
                'status' => $this->string($dispute, 'status'),
                'opened_at' => $this->timestamp($dispute, 'created'),
            ],
        );

        if ($record->user_id === null) {
            $owner = $this->owners->forCharge($record->stripe_charge_id ?? $charge);

            if ($owner !== null) {
                $record->forceFill(['user_id' => $owner->id])->save();
                $record->setRelation('user', $owner);

                // The row may already be closed and lost: it was created
                // without an owner, so nothing has applied its block yet,
                // and no redelivery will — the close event id is claimed.
                if (! $record->isOpen()) {
                    $owner->forceFill(['billing_blocked_at' => $this->blockUntil($record)])->save();
                }
            }
        }

        return $record;
    }

    /**
     * A won dispute only lifts the block when nothing else is still holding
     * it — an account with two chargebacks, one lost, stays blocked.
     */
    private function blockUntil(StripeDispute $record): ?CarbonImmutable
    {
        if ($record->isLost()) {
            return CarbonImmutable::now();
        }

        $otherLost = StripeDispute::query()
            ->where('user_id', $record->user_id)
            ->whereKeyNot($record->getKey())
            ->where('status', StripeDispute::STATUS_LOST)
            ->exists();

        return $otherLost ? CarbonImmutable::now() : null;
    }

    /**
     * @param  array<mixed, mixed>  $invoice
     */
    private function paymentFailed(array $invoice): void
    {
        // A failure event delivered after the success event for the same
        // invoice would tell the customer to fix a card that has paid.
        $settled = StripePayment::query()
            ->where('stripe_id', $this->string($invoice, 'id'))
            ->where('kind', StripePayment::KIND_PAYMENT)
            ->exists();

        if ($settled) {
            return;
        }

        $user = $this->owners->forCustomer($this->string($invoice, 'customer'));

        $user?->notify(new SubscriptionPaymentFailedNotification(
            $this->int($invoice, 'amount_due'),
            $this->string($invoice, 'currency'),
        ));
    }

    /**
     * Records the running total for one Stripe object and returns how much
     * that total grew, in minor units. A redelivered webhook grows it by
     * nothing, so it neither double-counts revenue nor re-alerts.
     */
    private function storeMoney(string $id, string $kind, int $amount, string $currency, ?User $user, CarbonImmutable $at): int
    {
        if ($id === '') {
            return 0;
        }

        $payment = StripePayment::query()->firstOrCreate(
            ['stripe_id' => $id, 'kind' => $kind],
            [
                'user_id' => $user?->id,
                'amount' => $amount,
                'currency' => mb_strtoupper($currency === '' ? 'eur' : $currency),
                'occurred_at' => $at,
            ],
        );

        if ($payment->wasRecentlyCreated) {
            return abs($amount);
        }

        // Two refund events for one charge can arrive together. Reading the
        // stored total without a lock lets the smaller one win and the
        // recorded refund go backwards, overstating revenue.
        $locked = StripePayment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            return 0;
        }

        $rise = abs($amount) - abs($locked->amount);

        if ($rise > 0) {
            $locked->forceFill(['amount' => $amount, 'occurred_at' => $at])->save();
        }

        return max(0, $rise);
    }

    private function alertOwners(BillingIncidentNotification $notification): void
    {
        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notification);
        }
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * When the invoice was actually paid, falling back to when it was
     * raised if Stripe did not say.
     *
     * @param  array<mixed, mixed>  $invoice
     */
    private function paidAt(array $invoice): CarbonImmutable
    {
        $transitions = $invoice['status_transitions'] ?? null;

        if (is_array($transitions) && is_numeric($transitions['paid_at'] ?? null)) {
            return CarbonImmutable::createFromTimestampUTC((int) $transitions['paid_at']);
        }

        return $this->timestamp($invoice, 'created');
    }

    /**
     * When the most recent refund on this charge happened.
     *
     * @param  array<mixed, mixed>  $charge
     */
    private function refundedAt(array $charge): CarbonImmutable
    {
        $refunds = $charge['refunds']['data'] ?? null;
        $latest = 0;

        if (is_array($refunds)) {
            foreach ($refunds as $refund) {
                $created = is_array($refund) ? ($refund['created'] ?? null) : null;

                if (is_numeric($created)) {
                    $latest = max($latest, (int) $created);
                }
            }
        }

        return $latest > 0
            ? CarbonImmutable::createFromTimestampUTC($latest)
            : $this->timestamp($charge, 'created');
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    private function timestamp(array $data, string $key): CarbonImmutable
    {
        $value = $data[$key] ?? null;

        return is_numeric($value)
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : CarbonImmutable::now();
    }
}
