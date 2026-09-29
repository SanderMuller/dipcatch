<?php declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * Thrown when a creation would cross a plan limit. Carries the message the
 * user sees — a limit is a normal state, not a fault, so the wording names
 * the limit and the way past it.
 */
final class PlanLimitReached extends RuntimeException
{
    public static function products(int $limit, Plan $plan = Plan::Free): self
    {
        if ($plan === Plan::Pro) {
            return new self(__('Pro tracks up to :limit products. Remove one before you add another.', ['limit' => $limit]));
        }

        $proLimit = Entitlements::of(Plan::Pro)->maxProducts();

        return new self(trans_choice(
            'Your plan tracks one product. Upgrade to Pro to track up to :pro, or remove it first.|Your plan tracks up to :limit products. Upgrade to Pro to track up to :pro, or remove one first.',
            $limit,
            ['limit' => $limit, 'pro' => $proLimit ?? __('any number')],
        ));
    }

    public static function shops(int $limit): self
    {
        return new self(trans_choice(
            'Your plan compares one shop per product. Upgrade to Pro for unlimited shops, or remove it first.|Your plan compares up to :limit shops per product. Upgrade to Pro for unlimited shops, or remove one first.',
            $limit,
            ['limit' => $limit],
        ));
    }
}
