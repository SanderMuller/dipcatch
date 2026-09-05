<?php declare(strict_types=1);

it('refuses an unsigned webhook', function (): void {
    config()->set('cashier.webhook.secret', 'whsec_test');

    $this->postJson('/stripe/webhook', ['type' => 'invoice.payment_succeeded'])
        ->assertForbidden();
});

it('refuses a webhook with a forged signature', function (): void {
    config()->set('cashier.webhook.secret', 'whsec_test');

    $this->call(
        'POST',
        '/stripe/webhook',
        server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        content: '{"type":"invoice.payment_succeeded"}',
    )->assertForbidden();
});

it('refuses every webhook when no secret is configured, rather than trusting it', function (): void {
    // The failure mode this guards: Cashier only attaches its signature
    // middleware when a secret exists, so its own route would accept a
    // forged chargeback and block an account.
    config()->set('cashier.webhook.secret', null);

    $this->postJson('/stripe/webhook', ['type' => 'charge.dispute.closed'])
        ->assertForbidden();
});
