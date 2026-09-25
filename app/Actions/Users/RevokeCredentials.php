<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends every way in to an account that lives outside its own row.
 *
 * A password change ends none of them, and Passport and the session table
 * have no foreign key, so even deleting the user leaves them working. Every
 * path that takes an account away from someone uses this one list. Callers
 * run it inside their own transaction.
 *
 * The `sessions` delete only reaches sessions under the `database` driver;
 * a Redis session is keyed by its id, not by the user, and survives this.
 */
final readonly class RevokeCredentials
{
    public function __invoke(User $user): void
    {
        // A subquery rather than a plucked list, so a token exchanged while
        // this runs is not missed between the read and the delete.
        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', DB::table('oauth_access_tokens')->select('id')->where('user_id', $user->getKey()))
            ->delete();
        DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->getKey())->delete();
        DB::table('oauth_device_codes')->where('user_id', $user->getKey())->delete();
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $user->passkeys()->delete();
        $user->socialAccounts()->delete();
    }
}
