<?php declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\LocaleCurrency;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser as SocialiteUser;

/**
 * Turns a provider account into the local account it belongs to.
 *
 * Three paths, in order: the provider account is already linked, an existing
 * DipCatch account owns the same address, or nobody does and the account is
 * new.
 */
final class ResolveSocialUser
{
    public function __invoke(SocialProvider $provider, SocialiteUser $socialiteUser, ?string $acceptLanguage = null): User
    {
        $providerId = trim((string) $socialiteUser->getId());

        if ($providerId === '') {
            throw SocialLoginFailed::missingAccountId($provider);
        }

        $email = $this->email($socialiteUser);
        $created = false;

        $user = DB::transaction(function () use ($provider, $socialiteUser, $providerId, $email, $acceptLanguage, &$created): User {
            $account = SocialAccount::query()
                ->where('provider', $provider)
                ->where('provider_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($account !== null) {
                return $account->user;
            }

            if ($email === null) {
                throw SocialLoginFailed::missingEmail($provider);
            }

            $user = $this->userOwningTheAddress($provider, $email);

            if ($user !== null) {
                $this->claimExistingAccount($provider, $socialiteUser, $user);
            }

            if ($user === null) {
                $user = $this->createUser($socialiteUser, $email, $acceptLanguage);
                $created = true;
            }

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $providerId,
            ]);

            return $user;
        });

        if ($created) {
            // Outside the transaction on purpose. `SendEmailVerificationNotification`
            // listens to this and `VerifyEmail` is not queued, so firing it inside
            // would hold a write transaction open across a call to the mail
            // provider — and a failure there would roll the new account back after
            // the mail had already gone out. Fortify's own register controller
            // fires it outside any transaction too.
            event(new Registered($user));
        }

        return $user;
    }

    /**
     * The account that already holds this address, matched case-insensitively.
     *
     * Fortify lower-cases the username on register and on profile update
     * (`fortify.lowercase_usernames`), but an invitation does not — the admin
     * form stores the address exactly as typed and `InvitationController`
     * passes it straight through. `users.email` is byte-unique on Postgres, so
     * a mixed-case row is reachable and a case-sensitive match would hand the
     * user a second, empty account instead of the one holding their products.
     */
    private function userOwningTheAddress(SocialProvider $provider, string $email): ?User
    {
        $matches = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            // Two rows differing only in case. Which one the person meant is
            // not knowable here, and picking either silently resets a password
            // on an account that may not be theirs.
            throw SocialLoginFailed::ambiguousEmail($provider);
        }

        return $matches->first();
    }

    /**
     * Decide whether this provider account may sign in as an account that
     * already owns the address, and make it safe to do so.
     */
    private function claimExistingAccount(SocialProvider $provider, SocialiteUser $socialiteUser, User $user): void
    {
        if (! $this->providerVerifiedTheEmail($socialiteUser)) {
            // Without the provider's word that it owns the address, this would
            // hand over an existing account to anyone who can set that address
            // on a provider account they control.
            throw SocialLoginFailed::unverifiedEmail($provider);
        }

        if ($user->socialAccounts()->where('provider', $provider)->exists()) {
            // A different account at the same provider. The unique index on
            // (user_id, provider) would reject the insert with a 500; refuse
            // it here with something the user can act on.
            throw SocialLoginFailed::alreadyLinked($provider);
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        // Nobody ever proved they own this mailbox, so nothing already on the
        // row is evidence of ownership: anyone can register an address that is
        // not theirs and wait. The provider has now proved it, which makes the
        // person signing in the rightful owner and every credential set before
        // this moment untrusted.
        //
        // Every one of them has to go, not only the password. Fortify's
        // passkey and two-factor routes sit behind `auth` and `password.confirm`
        // but not `verified`, so the squatter can register a passkey and enable
        // two-factor on an unverified account. Leaving either behind hands them
        // a way back in — and a stale two-factor secret would lock the rightful
        // owner out at a challenge they cannot answer.
        $user->forceFill([
            'password' => Str::password(),
            'email_verified_at' => now(),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $user->passkeys()->delete();

        // A password change does not end a live session on its own: the app
        // does not use `AuthenticateSession`, and the session driver is the
        // database. Same table `DeleteUser` clears.
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }

    private function createUser(SocialiteUser $socialiteUser, string $email, ?string $acceptLanguage): User
    {
        $user = new User();

        $user->forceFill([
            'name' => $this->name($socialiteUser, $email),
            'email' => $email,
            // The account has no password anyone knows. `Str::password()`
            // fills the non-nullable column with a value no one can guess;
            // the user sets a real one through the reset flow if they ever
            // want to enable two-factor or a passkey, both of which confirm
            // the password first.
            'password' => Str::password(),
            // The provider signed this address. A second verification mail
            // would only ask the user to prove what Google or Apple already
            // proved, and `MustVerifyEmail` would block the dashboard until
            // they did.
            'email_verified_at' => $this->providerVerifiedTheEmail($socialiteUser) ? now() : null,
            'default_currency' => LocaleCurrency::guess($acceptLanguage),
        ])->save();

        return $user;
    }

    /**
     * Apple sends the name only on the very first authorization, and never
     * again. Fall back to the local part of the address rather than storing
     * an empty name the whole app then has to defend against.
     */
    private function name(SocialiteUser $socialiteUser, string $email): string
    {
        $name = trim((string) $socialiteUser->getName());

        if ($name === '') {
            $name = Str::before($email, '@');
        }

        return Str::limit($name, 255, '');
    }

    private function email(SocialiteUser $socialiteUser): ?string
    {
        $email = Str::lower(trim((string) $socialiteUser->getEmail()));

        return $email === '' ? null : $email;
    }

    /**
     * Google returns a boolean, Apple a string claim on the identity token.
     * Both live on the raw payload under the same name.
     */
    private function providerVerifiedTheEmail(SocialiteUser $socialiteUser): bool
    {
        $raw = $socialiteUser->getRaw();

        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
