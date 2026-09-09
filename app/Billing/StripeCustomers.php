<?php declare(strict_types=1);

namespace App\Billing;

use Laravel\Cashier\Cashier;

/**
 * Removes a customer at Stripe. A seam, so account deletion can be driven
 * in tests without a network call.
 *
 * Deleting the customer cancels every subscription on it, which is the one
 * call that guarantees a deleted account is never billed again. Charges,
 * invoices and disputes survive it, so the payment history this app keeps
 * stays true.
 */
class StripeCustomers
{
    /**
     * Unlike `CheckoutSessions::find()` this throws. A delete that cannot
     * reach Stripe must stop, not continue and leave the card charged.
     */
    public function delete(string $stripeId): void
    {
        Cashier::stripe()->customers->delete($stripeId, []);
    }
}
