<?php declare(strict_types=1);

use App\Billing\StripeTax;

it('stays off until it is switched on', function (): void {
    // The safe default: with automatic_tax on and Stripe Tax inactive on the
    // account, every checkout fails rather than charging no tax.
    config()->set('plans.stripe.automatic_tax', false);

    expect(StripeTax::isEnabled())->toBeFalse()
        ->and(StripeTax::checkoutOptions())->toBe([]);
});

it('tells Stripe to save the address it collects', function (): void {
    config()->set('plans.stripe.automatic_tax', true);

    // Cashier sets automatic_tax, tax ID collection and customer_update[name],
    // but not the address. Without it Stripe refuses a taxed session for a
    // customer it already knows — and the address is what decides the rate.
    expect(StripeTax::checkoutOptions())->toBe(['customer_update' => ['address' => 'auto']]);
});

it('reads the flag as a boolean, whatever the environment writes', function (): void {
    foreach (['true', '1', 'on'] as $truthy) {
        config()->set('plans.stripe.automatic_tax', filter_var($truthy, FILTER_VALIDATE_BOOLEAN));

        expect(StripeTax::isEnabled())->toBeTrue();
    }

    foreach (['false', '0', ''] as $falsy) {
        config()->set('plans.stripe.automatic_tax', filter_var($falsy, FILTER_VALIDATE_BOOLEAN));

        expect(StripeTax::isEnabled())->toBeFalse();
    }
});
