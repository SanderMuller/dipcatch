<?php declare(strict_types=1);

use App\Billing\ChargeOwnerResolver;
use App\Models\Product;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use App\Models\User;
use App\Notifications\BillingIncidentNotification;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Illuminate\Support\Facades\Notification;
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

function webhook(string $type, array $object): void
{
    event(new WebhookReceived(['type' => $type, 'data' => ['object' => $object]]));
}

function customer(): User
{
    return User::factory()->create(['stripe_id' => 'cus_test123']);
}

it('records a paid invoice once, however often Stripe redelivers it', function (): void {
    $user = customer();

    $payload = ['id' => 'in_1', 'customer' => 'cus_test123', 'amount_paid' => 499, 'currency' => 'eur', 'created' => now()->timestamp];

    webhook('invoice.payment_succeeded', $payload);
    webhook('invoice.payment_succeeded', $payload);

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

    webhook('charge.refunded', $payload);
    webhook('charge.refunded', $payload);

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

it('ignores the close of a dispute it never saw', function (): void {
    Notification::fake();

    webhook('charge.dispute.closed', ['id' => 'dp_unknown', 'status' => 'lost']);

    expect(StripeDispute::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('does not re-close a dispute Stripe redelivers', function (): void {
    Notification::fake();

    $user = customer();
    StripeDispute::factory()->lost()->create(['user_id' => $user->id, 'stripe_id' => 'dp_3']);

    webhook('charge.dispute.closed', ['id' => 'dp_3', 'status' => 'lost']);

    Notification::assertNothingSent();
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

it('tells the customer when the card is declined', function (): void {
    Notification::fake();

    $user = customer();

    webhook('invoice.payment_failed', ['id' => 'in_2', 'customer' => 'cus_test123', 'amount_due' => 499, 'currency' => 'eur']);

    Notification::assertSentTo($user, SubscriptionPaymentFailedNotification::class);
});
