<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Billing\CheckoutSessions;
use App\Billing\StripeCustomers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes an account and everything that hangs off it.
 *
 * Products, price drop events, imports and exports go with the row through
 * their cascading foreign keys. The tables Cashier, Passport and web push
 * own have no such key, so they are cleared here. Payment and dispute rows
 * keep their history and only lose the user reference.
 */
final class DeleteUser
{
    public function __construct(
        private readonly CheckoutSessions $sessions,
        private readonly StripeCustomers $customers,
    ) {}

    public function __invoke(User $user, User $actor): void
    {
        $this->stopBilling($user);

        DB::transaction(function () use ($user): void {
            DB::table('subscription_items')
                ->whereIn('subscription_id', $user->subscriptions()->pluck('id'))
                ->delete();

            $user->subscriptions()->delete();

            self::deleteAuthRows($user);

            $user->notifications()->delete();
            $user->pushSubscriptions()->delete();

            $user->delete();
        });

        // The row is gone, so the remember token is too. Without this a
        // later logout on the same instance cycles the token and saves the
        // model, which re-inserts the account Laravel just deleted.
        $user->setRememberToken('');

        // A delete leaves no row behind to read afterwards, so the email is
        // logged with the id.
        Log::info('User deleted', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'by' => $actor->getKey(),
        ]);
    }

    /**
     * Everything Stripe could still charge this card for, and on purpose
     * before the database work: an account that is gone while Stripe keeps
     * billing is worse than a delete that did not happen.
     *
     * Deleting the customer cancels its subscriptions, including one whose
     * webhook has not landed yet — so the local rows are never the source
     * of truth here. The open Checkout session goes first, because a
     * session that completes after the customer is gone is the same charge
     * by another route.
     */
    private function stopBilling(User $user): void
    {
        if ($user->stripe_id === null) {
            return;
        }

        $sessionId = $user->stripe_checkout_session_id;

        if (is_string($sessionId) && $this->sessions->find($sessionId)?->isOpen() === true) {
            $this->sessions->expire($sessionId);
        }

        $this->customers->delete($user->stripe_id);
    }

    /**
     * Passport and the session table key on the user without a foreign key,
     * so a deleted account otherwise keeps working credentials. Refresh
     * tokens hang off the access token id and go first.
     */
    private static function deleteAuthRows(User $user): void
    {
        $accessTokenIds = DB::table('oauth_access_tokens')
            ->where('user_id', $user->getKey())
            ->pluck('id');

        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $accessTokenIds)->delete();
        DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->getKey())->delete();
        DB::table('oauth_device_codes')->where('user_id', $user->getKey())->delete();
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
