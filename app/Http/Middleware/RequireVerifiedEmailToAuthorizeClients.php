<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account must prove its address before it can approve an OAuth client.
 *
 * Anyone can register someone else's address and sit on it unverified. Without
 * this, that squatter could approve an MCP client and keep its token after the
 * real owner claims the account. Passport registers these routes itself, so
 * the check rides the `web` group and matches them by name. A guest passes:
 * Passport sends a guest to the login page first.
 */
final class RequireVerifiedEmailToAuthorizeClients
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs('passport.authorizations.*', 'passport.device*')
            && $user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()) {
            // Back to this authorisation once the address is verified.
            return Redirect::guest(route('verification.notice'));
        }

        /** @var Response */
        return $next($request);
    }
}
