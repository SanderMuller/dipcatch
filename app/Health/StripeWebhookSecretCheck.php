<?php declare(strict_types=1);

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Cashier applies its signature middleware only when a webhook secret is
 * set. Without one the endpoint accepts any post, and this app acts on
 * those posts — a forged `charge.dispute.closed` would block an account.
 *
 * The check is only meaningful once Stripe is configured at all: an
 * install with no Stripe secret sells nothing and has no endpoint.
 */
class StripeWebhookSecretCheck extends Check
{
    public function run(): Result
    {
        $stripeConfigured = $this->filled('cashier.secret');

        if (! $stripeConfigured) {
            return Result::make()
                ->ok('Stripe is not configured; no webhook to protect.')
                ->shortSummary('not configured');
        }

        if (! $this->filled('cashier.webhook.secret')) {
            return Result::make()
                ->failed('STRIPE_WEBHOOK_SECRET is not set, so Stripe webhooks are accepted unsigned.')
                ->shortSummary('unsigned');
        }

        return Result::make()
            ->ok('Stripe webhooks are signature-verified.')
            ->shortSummary('verified');
    }

    private function filled(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }
}
