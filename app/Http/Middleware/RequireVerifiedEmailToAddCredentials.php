<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account must prove its address before it can add a way in: approve an
 * OAuth client, register a passkey, or turn on two-factor.
 *
 * Anyone can register someone else's address and sit on it unverified. A
 * credential added from there would outlive the real owner's claim, and a
 * two-factor secret would lock the owner out at a challenge they cannot
 * answer. Passport, Fortify and the passkeys package register these routes
 * themselves, so the check rides the `web` group and matches them by name.
 * Removing a factor stays open. A guest passes: those routes send a guest to
 * the login page first.
 */
final class RequireVerifiedEmailToAddCredentials
{
    private const array ROUTES = [
        'passport.authorizations.*',
        'passport.device*',
        'passkey.registration-options',
        'passkey.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.regenerate-recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs(...self::ROUTES)
            && $user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()) {
            // A script calling these gets a status, not a page. A page comes
            // back here once the address is verified; a POST or JSON endpoint
            // would come back to a 405 or raw JSON, so it is not remembered.
            if ($request->expectsJson()) {
                abort(403, 'Your email address is not verified.');
            }

            return $request->isMethod('GET')
                ? Redirect::guest(route('verification.notice'))
                : Redirect::route('verification.notice');
        }

        /** @var Response */
        return $next($request);
    }
}
