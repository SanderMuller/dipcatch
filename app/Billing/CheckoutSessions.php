<?php declare(strict_types=1);

namespace App\Billing;

use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Reads a Checkout session back from Stripe. A seam, so the checkout flow
 * can be driven in tests without a network call.
 */
class CheckoutSessions
{
    /**
     * Null when there is no answer — Stripe is unreachable, or the session
     * is gone. A missing answer must never block a customer from paying, so
     * callers fall through to starting a fresh session.
     */
    public function find(string $sessionId): ?CheckoutSession
    {
        if ($sessionId === '') {
            return null;
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, []);
        } catch (Throwable) {
            return null;
        }

        return new CheckoutSession(
            is_string($session->status) ? $session->status : '',
            is_string($session->url) ? $session->url : null,
        );
    }
}
