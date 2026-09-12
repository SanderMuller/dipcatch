<?php declare(strict_types=1);

namespace App\Health;

use App\Billing\BillingGate;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Says whether the shop is open, and names what is missing when it is not.
 * A half-configured Stripe is the dangerous state: enough to look ready,
 * not enough to turn a payment into a subscription.
 */
class BillingConfigurationCheck extends Check
{
    public function run(): Result
    {
        $missing = BillingGate::missing();

        if ($missing !== []) {
            return Result::make()
                ->ok('Pro is not on sale: ' . implode(', ', $missing) . ' not set.')
                ->shortSummary('not selling');
        }

        if (! BillingGate::isOpen()) {
            return Result::make()
                ->ok('Stripe is configured, but BILLING_ENABLED switches the shop off.')
                ->shortSummary('switched off');
        }

        return Result::make()
            ->ok('Pro is on sale.')
            ->shortSummary('selling');
    }
}
