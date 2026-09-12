<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Deep links into the Stripe dashboard. Test-mode keys point at the test
 * dashboard, so a link never opens the wrong account's live data.
 */
final class StripeDashboard
{
    public static function customerUrl(?string $customerId): ?string
    {
        return $customerId === null || $customerId === ''
            ? null
            : self::base() . 'customers/' . $customerId;
    }

    public static function disputeUrl(?string $disputeId): ?string
    {
        return $disputeId === null || $disputeId === ''
            ? null
            : self::base() . 'payments/disputes/' . $disputeId;
    }

    private static function base(): string
    {
        // `config()` rather than a typed read: the key exists but is null
        // until a secret is set, and a missing key must not blow up a table.
        $secret = config('cashier.secret');
        $secret = is_string($secret) ? $secret : '';

        return Str::startsWith($secret, 'sk_test')
            ? 'https://dashboard.stripe.com/test/'
            : 'https://dashboard.stripe.com/';
    }
}
