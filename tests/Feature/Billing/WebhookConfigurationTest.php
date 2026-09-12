<?php declare(strict_types=1);

use App\Health\StripeWebhookSecretCheck;
use Spatie\Health\Enums\Status;

it('subscribes the endpoint to every event the app acts on', function (): void {
    // `cashier:webhook` creates the Stripe endpoint from this list. An event
    // missing here never reaches HandleStripeWebhook in production, however
    // green its local test is.
    expect(config('cashier.webhook.events'))
        ->toContain('invoice.payment_failed')
        ->toContain('invoice.payment_succeeded')
        ->toContain('charge.refunded')
        ->toContain('charge.dispute.created')
        ->toContain('charge.dispute.closed');
});

it('fails the health check when a configured Stripe accepts unsigned webhooks', function (): void {
    config()->set('cashier.secret', 'sk_test_123');
    config()->set('cashier.webhook.secret');

    expect(new StripeWebhookSecretCheck()->run()->status)->toBe(Status::failed());
});

it('passes the health check once the webhook secret is set', function (): void {
    config()->set('cashier.secret', 'sk_test_123');
    config()->set('cashier.webhook.secret', 'whsec_123');

    expect(new StripeWebhookSecretCheck()->run()->status)->toBe(Status::ok());
});

it('stays quiet when Stripe is not configured at all', function (): void {
    config()->set('cashier.secret');

    expect(new StripeWebhookSecretCheck()->run()->status)->toBe(Status::ok());
});
