<?php declare(strict_types=1);

namespace App\Billing;

enum Plan: string
{
    /** The Cashier subscription name. One paid plan, one name. */
    public const string SUBSCRIPTION_TYPE = 'pro';

    /**
     * The date a comp with no end is written as. A far-future timestamp
     * rather than a second "forever" flag, so there is no state where the
     * flag and the date disagree.
     *
     * Safe because this app runs Postgres, and SQLite in tests. On MySQL a
     * `TIMESTAMP` column stops at 2038 and this would be a live bug.
     */
    public const string COMPED_FOREVER = '2099-12-31 00:00:00';

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
