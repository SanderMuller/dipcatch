<?php declare(strict_types=1);

namespace App\Billing;

use App\Support\MoneyFormatter;

/**
 * What Pro costs, read once. Stripe remains the authority on what a customer
 * is actually charged — these values are for display and for pointing the
 * checkout at the right Stripe Price.
 */
final class ProPrice
{
    public static function priceId(): string
    {
        return self::text('pro_price_id', '');
    }

    public static function isConfigured(): bool
    {
        return self::priceId() !== '';
    }

    public static function currency(): string
    {
        return self::text('pro_currency', 'EUR');
    }

    public static function amount(): string
    {
        $amount = config('plans.stripe.pro_amount');

        return is_numeric($amount) ? (string) $amount : '2.99';
    }

    public static function label(): string
    {
        return MoneyFormatter::format(self::amount(), self::currency());
    }

    public static function trialDays(): int
    {
        $days = config('plans.stripe.trial_days');

        return is_numeric($days) ? max(0, (int) $days) : 0;
    }

    private static function text(string $key, string $default): string
    {
        // The keys exist but are null until Stripe is configured, so a typed
        // config read would throw rather than fall back.
        $value = config("plans.stripe.{$key}");

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
