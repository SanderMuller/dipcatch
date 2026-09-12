<?php declare(strict_types=1);

use App\Billing\ChargeOwnerResolver;
use App\Jobs\RelinkStripeDispute;
use App\Models\Product;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use App\Models\User;
use App\Notifications\BillingIncidentNotification;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * A dispute payload names a charge, not a customer, so the real resolver
 * calls Stripe. The suite never does — this stub answers from the charge id.
 */
function resolveChargesTo(?User $user): void
{
    app()->instance(ChargeOwnerResolver::class, new class ($user) extends ChargeOwnerResolver {
        public function __construct(private readonly ?User $user) {}

        public function forCharge(?string $chargeId): ?User
        {
            return $this->user;
        }
    });
}

/**
 * One delivery. Stripe gives every delivery its own event id, and sends the
 * same id again when it redelivers — pass `$eventId` to replay one.
 */
function webhook(string $type, array $object, ?string $eventId = null): void
{
    event(new WebhookReceived([
        'id' => $eventId ?? 'evt_' . Str::random(16),
        'type' => $type,
        'data' => ['object' => $object],
    ]));
}

function customer(): User
{
    return User::factory()->create(['stripe_id' => 'cus_test123']);
}

it('records a paid invoice once, however often Stripe redelivers it', function (): void {
    $user = customer();

    $payload = ['id' => 'in_1', 'customer' => 'cus_test123', 'amount_paid' => 499, 'currency' => 'eur', 'created' => now()->timestamp];

    webhook('invoice.payment_succeeded', $payload, 'evt_paid');
    webhook('invoice.payment_succeeded', $payload, 'evt_paid');

    expect(StripePayment::query()->count())->toBe(1);

    $payment = StripePayment::query()->firstOrFail();

    expect($payment->amount)->toBe(499)
        ->and($payment->currency)->toBe('EUR')
        ->and($payment->user_id)->toBe($user->id);
});

it('records a refund as negative money and tells the owner once', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['is_admin' => true]);
    customer();

    $payload = ['id' => 'ch_1', 'customer' => 'cus_test123', 'amount_refunded' => 499, 'currency' => 'eur', 'created' => now()->timestamp];

    webhook('charge.refunded', $payload, 'evt_refund');
    webhook('charge.refunded', $payload, 'evt_refund');

    expect(StripePayment::query()->where('kind', StripePayment::KIND_REFUND)->count())->toBe(1)
        ->and(StripePayment::query()->sum('amount'))->toBe(-499);

    Notification::assertSentToTimes($admin, BillingIncidentNotification::class, 1);
});

it('keeps pro while a chargeback is open and alerts the owner', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['is_admin' => true]);
    $user = customer();
    resolveChargesTo($user);

    webhook('charge.dispute.created', [
        'id' => 'dp_1', 'charge' => 'ch_1', 'amount' => 499,
        'currency' => 'eur', 'reason' => 'fraudulent', 'status' => 'needs_response',
        'created' => now()->timestamp,
    ]);

    expect(StripeDispute::query()->count())->toBe(1)
        ->and($user->fresh()?->billing_blocked_at)->toBeNull();

    Notification::assertSentTo($admin, BillingIncidentNotification::class);
});

it('revokes pro when a chargeback is lost, without touching tracked data', function (): void {
    Notification::fake();

    $user = customer();
    resolveChargesTo($user);
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);
    StripeDispute::factory()->create(['user_id' => $user->id, 'stripe_id' => 'dp_1']);

    expect($user->isPro())->toBeTrue();

    webhook('charge.dispute.closed', ['id' => 'dp_1', 'status' => 'lost']);

    expect($user->fresh()?->billing_blocked_at)->not->toBeNull()
        ->and($user->fresh()?->isPro())->toBeFalse()
        // The subscription row is untouched — Stripe still owns it — and so
        // is everything the customer tracks.
        ->and($product->fresh()?->active)->toBeTrue();
});

it('restores pro when a chargeback is won', function (): void {
    Notification::fake();

    $user = customer();
    $user->forceFill(['billing_blocked_at' => now()])->save();
    StripeDispute::factory()->create(['user_id' => $user->id, 'stripe_id' => 'dp_2']);

    webhook('charge.dispute.closed', ['id' => 'dp_2', 'status' => 'won']);

    expect($user->fresh()?->billing_blocked_at)->toBeNull();
});

it('blocks the account when the close arrives before the open', function (): void {
    Notification::fake();

    $user = customer();
    resolveChargesTo($user);

    // Stripe promises delivery, not order.
    webhook('charge.dispute.closed', ['id' => 'dp_ooo', 'charge' => 'ch_ooo', 'amount' => 499, 'currency' => 'eur', 'status' => 'lost']);

    expect(StripeDispute::query()->count())->toBe(1)
        ->and($user->fresh()?->billing_blocked_at)->not->toBeNull();

    // The create event then arrives for a dispute already closed and lost.
    webhook('charge.dispute.created', ['id' => 'dp_ooo', 'charge' => 'ch_ooo', 'amount' => 499, 'currency' => 'eur', 'status' => 'needs_response', 'created' => now()->timestamp]);

    expect(StripeDispute::query()->count())->toBe(1)
        ->and(StripeDispute::query()->firstOrFail()->isLost())->toBeTrue()
        ->and($user->fresh()?->billing_blocked_at)->not->toBeNull();
});

it('blocks the account when the owner is only linked after a lost close', function (): void {
    Notification::fake();

    $user = customer();
    // The close lands first AND the owner lookup fails: the row is stored
    // lost but unlinked, and no redelivery will revisit it.
    resolveChargesTo(null);
    webhook('charge.dispute.closed', ['id' => 'dp_late', 'charge' => 'ch_late', 'amount' => 499, 'currency' => 'eur', 'status' => 'lost']);

    expect($user->fresh()?->billing_blocked_at)->toBeNull();

    // The create event arrives and Stripe answers this time.
    resolveChargesTo($user);
    webhook('charge.dispute.created', ['id' => 'dp_late', 'charge' => 'ch_late', 'amount' => 499, 'currency' => 'eur', 'status' => 'needs_response', 'created' => now()->timestamp]);

    expect($user->fresh()?->billing_blocked_at)->not->toBeNull();
});

it('links the owner on a later event when the first Stripe lookup failed', function (): void {
    Notification::fake();

    $user = customer();
    // The lookup fails: Stripe is down, or the charge is not readable yet.
    resolveChargesTo(null);

    webhook('charge.dispute.created', ['id' => 'dp_retry', 'charge' => 'ch_retry', 'amount' => 499, 'currency' => 'eur', 'status' => 'needs_response', 'created' => now()->timestamp]);

    expect(StripeDispute::query()->firstOrFail()->user_id)->toBeNull();

    // Stripe answers the next time, and the lost dispute still blocks.
    resolveChargesTo($user);
    webhook('charge.dispute.closed', ['id' => 'dp_retry', 'charge' => 'ch_retry', 'status' => 'lost']);

    expect(StripeDispute::query()->firstOrFail()->user_id)->toBe($user->id)
        ->and($user->fresh()?->billing_blocked_at)->not->toBeNull();
});

it('does not re-close a dispute Stripe redelivers', function (): void {
    Notification::fake();

    $user = customer();
    StripeDispute::factory()->lost()->create(['user_id' => $user->id, 'stripe_id' => 'dp_3']);

    webhook('charge.dispute.closed', ['id' => 'dp_3', 'status' => 'lost']);

    Notification::assertNothingSent();
});

it('sends one alert when Stripe redelivers the same failed payment', function (): void {
    Notification::fake();

    $user = customer();
    $payload = ['id' => 'in_2', 'customer' => 'cus_test123', 'amount_due' => 499, 'currency' => 'eur'];

    webhook('invoice.payment_failed', $payload, 'evt_failed');
    webhook('invoice.payment_failed', $payload, 'evt_failed');

    Notification::assertSentToTimes($user, SubscriptionPaymentFailedNotification::class, 1);
});

it('counts a second partial refund on the same charge', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['is_admin' => true]);
    customer();

    // Stripe reports the running total on the charge, so a second partial
    // refund arrives as the same event id with a larger amount.
    webhook('charge.refunded', ['id' => 'ch_1', 'customer' => 'cus_test123', 'amount_refunded' => 200, 'currency' => 'eur', 'created' => now()->timestamp]);
    webhook('charge.refunded', ['id' => 'ch_1', 'customer' => 'cus_test123', 'amount_refunded' => 499, 'currency' => 'eur', 'created' => now()->timestamp]);

    expect(StripePayment::query()->where('kind', StripePayment::KIND_REFUND)->count())->toBe(1)
        ->and(StripePayment::query()->sum('amount'))->toBe(-499);

    // Two alerts: the first refund, then the rise. Not three.
    Notification::assertSentToTimes($admin, BillingIncidentNotification::class, 2);
});

it('keeps an account blocked when it wins one chargeback but lost another', function (): void {
    Notification::fake();

    $user = customer();
    resolveChargesTo($user);
    StripeDispute::factory()->lost()->create(['user_id' => $user->id, 'stripe_id' => 'dp_lost']);
    StripeDispute::factory()->create(['user_id' => $user->id, 'stripe_id' => 'dp_open']);
    $user->forceFill(['billing_blocked_at' => now()])->save();

    webhook('charge.dispute.closed', ['id' => 'dp_open', 'status' => 'won']);

    expect($user->fresh()?->billing_blocked_at)->not->toBeNull();
});

it('queues a retry when a lost dispute cannot be linked to an account', function (): void {
    Notification::fake();
    Queue::fake();

    // Nothing else would revisit it: Stripe promises no further event after
    // a close, and this event id is already claimed.
    resolveChargesTo(null);

    webhook('charge.dispute.closed', ['id' => 'dp_orphan', 'charge' => 'ch_orphan', 'amount' => 499, 'currency' => 'eur', 'status' => 'lost']);

    Queue::assertPushed(RelinkStripeDispute::class);
});

it('blocks the account when the queued retry finally links it', function (): void {
    Notification::fake();

    $user = customer();
    $dispute = StripeDispute::factory()->lost()->create(['user_id' => null, 'stripe_charge_id' => 'ch_orphan']);

    resolveChargesTo($user);
    new RelinkStripeDispute($dispute->id)->handle(app(ChargeOwnerResolver::class));

    expect($dispute->fresh()?->user_id)->toBe($user->id)
        ->and($user->fresh()?->billing_blocked_at)->not->toBeNull();
});

it('does not warn about a card that already paid the invoice', function (): void {
    Notification::fake();

    $user = customer();

    // The success lands first; the failure event for the same invoice is
    // delivered late.
    webhook('invoice.payment_succeeded', ['id' => 'in_late', 'customer' => 'cus_test123', 'amount_paid' => 499, 'currency' => 'eur', 'created' => now()->timestamp]);
    webhook('invoice.payment_failed', ['id' => 'in_late', 'customer' => 'cus_test123', 'amount_due' => 499, 'currency' => 'eur']);

    Notification::assertNotSentTo($user, SubscriptionPaymentFailedNotification::class);
});

it('dates money by when it moved, not when the object was raised', function (): void {
    customer();

    $raised = now()->subDays(9);
    $settled = now()->subDay();

    webhook('invoice.payment_succeeded', [
        'id' => 'in_dates', 'customer' => 'cus_test123', 'amount_paid' => 499, 'currency' => 'eur',
        'created' => $raised->timestamp,
        'status_transitions' => ['paid_at' => $settled->timestamp],
    ]);

    webhook('charge.refunded', [
        'id' => 'ch_dates', 'customer' => 'cus_test123', 'amount_refunded' => 499, 'currency' => 'eur',
        'created' => $raised->timestamp,
        'refunds' => ['data' => [['created' => $settled->timestamp]]],
    ]);

    $payment = StripePayment::query()->where('kind', StripePayment::KIND_PAYMENT)->firstOrFail();
    $refund = StripePayment::query()->where('kind', StripePayment::KIND_REFUND)->firstOrFail();

    expect($payment->occurred_at)->toBeSameTimestampAs($settled)
        ->and($refund->occurred_at)->toBeSameTimestampAs($settled);
});

it('tells the customer when the card is declined', function (): void {
    Notification::fake();

    $user = customer();

    webhook('invoice.payment_failed', ['id' => 'in_2', 'customer' => 'cus_test123', 'amount_due' => 499, 'currency' => 'eur']);

    Notification::assertSentTo($user, SubscriptionPaymentFailedNotification::class);
});
