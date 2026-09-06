<?php declare(strict_types=1);

namespace App\Billing;

use App\Models\User;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * A dispute payload names a charge, never a customer. Resolving the owner
 * therefore needs one Stripe call. It happens once per dispute webhook, not
 * per rendered row, and a failure is survivable: the dispute is still stored
 * and still alerts the owner, just without a linked account.
 */
class ChargeOwnerResolver
{
    public function forCharge(?string $chargeId): ?User
    {
        if ($chargeId === null || $chargeId === '') {
            return null;
        }

        try {
            $charge = Cashier::stripe()->charges->retrieve($chargeId, []);
        } catch (Throwable) {
            return null;
        }

        return $this->forCustomer(is_string($charge->customer) ? $charge->customer : null);
    }

    public function forCustomer(?string $customerId): ?User
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $billable = Cashier::findBillable($customerId);

        return $billable instanceof User ? $billable : null;
    }
}
