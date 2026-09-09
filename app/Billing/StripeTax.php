<?php declare(strict_types=1);

namespace App\Billing;

use Illuminate\Support\Facades\Config;

/**
 * Whether Stripe works out VAT for us, and what a Checkout session needs when
 * it does.
 *
 * Off by default. Stripe Tax has to be active on the account with at least one
 * registration behind it: with `automatic_tax` on and Stripe Tax inactive,
 * every checkout fails outright rather than merely charging no tax.
 *
 * The Pro price is `inclusive`, so the customer pays the advertised amount and
 * the VAT comes out of it. That is what EU consumer pricing expects, and it is
 * why the pricing page can keep saying one number to everyone. A Price whose
 * tax behaviour is still `unspecified` fails a taxed checkout, so the two are
 * changed together or not at all.
 */
final class StripeTax
{
    public static function isEnabled(): bool
    {
        return Config::boolean('plans.stripe.automatic_tax', false);
    }

    /**
     * The Checkout session options Stripe Tax adds.
     *
     * Cashier already sets `automatic_tax`, tax ID collection and
     * `customer_update[name]`, but not the address. Stripe refuses a taxed
     * session for a customer it already knows without it, and the address
     * collected at checkout is what the rate is worked out from.
     *
     * @return array<string, mixed>
     */
    public static function checkoutOptions(): array
    {
        return self::isEnabled() ? ['customer_update' => ['address' => 'auto']] : [];
    }
}
