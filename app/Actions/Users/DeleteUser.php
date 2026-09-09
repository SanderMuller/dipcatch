<?php declare(strict_types=1);

namespace App\Actions\Users;

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
    /**
     * Stripe statuses that already ended, so nothing is left to cancel.
     */
    private const array ENDED_STATUSES = ['canceled', 'incomplete_expired'];

    public function __invoke(User $user): void
    {
        // Stripe first, and on purpose. A failure here stops the delete: an
        // account that is gone while Stripe keeps charging the card is worse
        // than a delete that did not happen.
        foreach ($user->subscriptions()->whereNotIn('stripe_status', self::ENDED_STATUSES)->get() as $subscription) {
            $subscription->cancelNow();
        }

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

        // The comp actions log who did what. A delete leaves no row behind to
        // read afterwards, so the email is logged with the id.
        $actor = auth()->user();

        Log::info('User deleted', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'by' => $actor instanceof User ? $actor->getKey() : null,
        ]);
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
