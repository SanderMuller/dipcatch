<?php declare(strict_types=1);

namespace App\Billing;

use App\Models\User;

/**
 * Whether a Pro hint may show to this account, and what its button says.
 *
 * A Pro account sees none. With the shop closed nothing can be bought, so a
 * hint that only sells hides, and a hint that explains a limit keeps its
 * text without a button.
 */
final readonly class ProPitch
{
    private function __construct(public bool $canBuy, public bool $offersTrial) {}

    /** Asked by every hint on a page, so answered once per request and account. */
    public static function for(?User $user): ?self
    {
        return once(static fn (): ?self => self::resolve($user));
    }

    private static function resolve(?User $user): ?self
    {
        if (! $user instanceof User || $user->isPro()) {
            return null;
        }

        // Checkout refuses a blocked account and one Stripe already bills, so
        // neither is offered a button that only ends in a refusal.
        $canBuy = BillingGate::isOpen()
            && $user->billing_blocked_at === null
            && $user->payingSubscription()?->valid() !== true;

        return new self($canBuy, $canBuy && ProPrice::trialDays() > 0 && $user->qualifiesForTrial());
    }

    public function buttonLabel(): string
    {
        return $this->offersTrial
            ? __('Try Pro free for :days days', ['days' => ProPrice::trialDays()])
            : __('Get Pro');
    }
}
