<?php declare(strict_types=1);

namespace App\Billing;

enum Plan: string
{
    /** The Cashier subscription name. One paid plan, one name. */
    public const string SUBSCRIPTION_TYPE = 'pro';

    case Free = 'free';
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Pro => __('Pro'),
        };
    }

    public function isPro(): bool
    {
        return $this === self::Pro;
    }
}
