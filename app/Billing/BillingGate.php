<?php declare(strict_types=1);

namespace App\Billing;

/**
 * Whether the app may sell anything right now.
 *
 * Selling needs four things from Stripe, and every one of them is load
 * bearing: an API key and secret to talk to Stripe, a Price to subscribe
 * the customer to, and a webhook secret — without that last one the app
 * refuses every webhook, so a completed payment would never turn into a
 * subscription and the customer would pay for nothing.
 *
 * `BILLING_ENABLED` overrides the answer in both directions, which is what
 * keeps a fully configured staging environment from selling.
 *
 * This gate is about the shop, not about entitlements. Granting Pro by hand
 * — an account-level `trial_ends_at` — keeps working with the shop closed.
 */
final class BillingGate
{
    public static function isOpen(): bool
    {
        $override = config('plans.enabled');

        if (is_bool($override)) {
            return $override && self::isConfigured();
        }

        return self::isConfigured();
    }

    /**
     * Deliberately separate from {@see isOpen()}: the owner needs to see
     * that Stripe is wired up even while the shop is switched off.
     */
    public static function isConfigured(): bool
    {
        return ProPrice::isConfigured()
            && self::filled('cashier.key')
            && self::filled('cashier.secret')
            && self::filled('cashier.webhook.secret');
    }

    /**
     * What is still missing, for the owner's health check.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $required = [
            'STRIPE_KEY' => 'cashier.key',
            'STRIPE_SECRET' => 'cashier.secret',
            'STRIPE_WEBHOOK_SECRET' => 'cashier.webhook.secret',
        ];

        $missing = [];

        foreach ($required as $variable => $key) {
            if (! self::filled($key)) {
                $missing[] = $variable;
            }
        }

        if (! ProPrice::isConfigured()) {
            $missing[] = 'STRIPE_PRICE_PRO_MONTHLY';
        }

        return $missing;
    }

    private static function filled(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }
}
