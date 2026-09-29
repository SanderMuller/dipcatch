<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends every OAuth client's access to an account: the MCP connections to
 * Claude, ChatGPT and the like. Each has to be connected again afterwards.
 *
 * Passport's tables have no foreign key to users, so a password change or a
 * deleted user leaves them working.
 */
final readonly class RevokeOAuthAccess
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
    }
}
