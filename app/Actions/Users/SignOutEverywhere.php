<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Signs the signed-in account out of every other browser and every connected
 * app, and keeps the browser that asked signed in. For someone who suspects
 * another person has been in their account.
 *
 * It acts on the signed-in account only, because the password rehash it
 * relies on is the auth guard's. Passkeys, two-factor and linked sign-in
 * providers stay: the person manages those on the security page.
 */
final readonly class SignOutEverywhere
{
    public function __construct(private RevokeOAuthAccess $revokeOAuthAccess) {}

    public function __invoke(#[SensitiveParameter] string $password): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        // First, as the one step that can refuse the password. It saves the
        // same password with a new hash; every other session and remember-me
        // cookie holds the old one, so `AuthenticateSession` ends them on their
        // next request, whatever the session driver.
        Auth::logoutOtherDevices($password);

        ($this->revokeOAuthAccess)($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
