<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveSocialUser;
use App\Actions\Auth\SocialLoginFailed;
use App\Enums\SocialProvider;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Apple\Provider as AppleProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Sign in with Google or Apple.
 *
 * Google runs the standard stateful flow. Apple posts the callback cross-site
 * with `response_mode=form_post`, so it runs stateless against the provider's
 * encrypted nonce cookie rather than the session-backed `state` check. That
 * keeps Apple working whatever `session.same_site` is set to — this app ships
 * `none`, which does send the session cookie on that POST, but `lax` is the
 * safer setting and a deployment that chooses it must not break Apple login.
 */
class SocialLoginController extends Controller
{
    public function redirect(string $provider): Response
    {
        return $this->driver($this->resolveProvider($provider))->redirect();
    }

    public function callback(Request $request, string $provider, ResolveSocialUser $resolveSocialUser): RedirectResponse
    {
        $socialProvider = $this->resolveProvider($provider);

        try {
            $socialiteUser = $this->driver($socialProvider)->user();
        } catch (Throwable $e) {
            // A bad state, a cancelled consent screen and an expired nonce
            // all land here, and none of them is actionable for the user
            // beyond trying again. Logged so a broken provider config is
            // visible rather than silently turning into "please try again".
            Log::warning('Social login callback failed.', [
                'provider' => $socialProvider->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failed(SocialLoginFailed::cancelled($socialProvider));
        }

        if (! $socialiteUser instanceof AbstractUser) {
            return $this->failed(SocialLoginFailed::missingAccountId($socialProvider));
        }

        try {
            $user = $resolveSocialUser($socialProvider, $socialiteUser, $request->header('Accept-Language'));
        } catch (SocialLoginFailed $e) {
            return $this->failed($e);
        } catch (QueryException $e) {
            // Two callbacks for the same new account at once. `lockForUpdate`
            // locks nothing on a row that does not exist yet on Postgres, so
            // both reach the insert and the unique index rejects the loser.
            // The index is the guard that matters; this turns its 500 into a
            // retry the user can act on.
            Log::warning('Social login hit a unique constraint.', [
                'provider' => $socialProvider->value,
                'message' => $e->getMessage(),
            ]);

            return $this->failed(SocialLoginFailed::cancelled($socialProvider));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // The provider proved who owns the account, not that the person
            // holding the second factor agreed. Hand over to Fortify's
            // challenge exactly as a password login does.
            $request->session()->put([
                'login.id' => $user->getKey(),
                // Not remembered, unlike the path below. Fortify pulls this
                // straight into `Auth::login($user, $remember)` after the
                // challenge, and a recaller cookie then signs the user in
                // without one. Remembering by default would hand a 400-day
                // bypass to the people who deliberately turned a second factor
                // on, and the challenge form has no checkbox to decline it.
                //
                // Written, not left unset: an abandoned password login with
                // "Remember me" ticked leaves a stale `true` here, which this
                // callback would otherwise inherit.
                'login.remember' => false,
            ]);

            event(new TwoFactorAuthenticationChallenged($user));

            return redirect()->route('two-factor.login');
        }

        // `SessionGuard::login()` regenerates the session id itself, so the
        // fixation guard Fortify adds with PrepareAuthenticatedSession is
        // already covered here. `remember` is on because a social account
        // has no password to fall back on: the alternative to the cookie is
        // sending the user back through the provider every session.
        Auth::login($user, remember: true);

        return redirect()->intended(config()->string('fortify.home'));
    }

    /**
     * Both ends of the flow must build the driver the same way, or the nonce
     * the redirect sets never matches the one the callback checks.
     */
    private function driver(SocialProvider $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider->value);

        if ($provider !== SocialProvider::Apple) {
            assert($driver instanceof AbstractProvider);

            return $driver;
        }

        assert($driver instanceof AppleProvider);

        $stateless = $driver->stateless();
        assert($stateless instanceof AppleProvider);

        $withNonce = $stateless->cookieNonce();
        assert($withNonce instanceof AppleProvider);

        return $withNonce;
    }

    private function resolveProvider(string $provider): SocialProvider
    {
        $resolved = SocialProvider::tryFrom($provider);

        if ($resolved === null || ! $resolved->isConfigured()) {
            throw new NotFoundHttpException('Unknown social login provider.');
        }

        return $resolved;
    }

    private function failed(SocialLoginFailed $e): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
    }
}
