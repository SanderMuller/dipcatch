<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends every way in to an account that lives outside its own row.
 *
 * A password change ends only the browser sessions (`AuthenticateSession` in
 * the `web` group), and Passport and the session table have no foreign key,
 * so even deleting the user leaves the rest working. Every path that takes an
 * account away from someone uses this one list. Callers run it inside their
 * own transaction.
 *
 * The `sessions` delete only reaches sessions under the `database` driver; a
 * Redis session is keyed by its id, not by the user. It ends through the
 * password change instead.
 */
final readonly class RevokeCredentials
{
    public function __construct(private RevokeOAuthAccess $revokeOAuthAccess) {}

    public function __invoke(User $user): void
    {
        ($this->revokeOAuthAccess)($user);
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $user->passkeys()->delete();
        $user->socialAccounts()->delete();
        // Not a way in, but a squatter's browser would keep receiving the
        // owner's price alerts. The table is polymorphic, with no foreign key.
        $user->pushSubscriptions()->delete();
    }
}
