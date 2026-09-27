<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use SensitiveParameter;

/**
 * Hands an account nobody had proved they own to the person who just proved
 * it, through a provider that vouched for the address or a reset link that
 * reached the mailbox.
 *
 * Nothing already on the row is evidence of ownership: anyone can register an
 * address that is not theirs and wait. So every credential set before this
 * moment goes, not only the password. A squatter's passkey or two-factor
 * secret left behind would be a way back in, and a stale two-factor secret
 * would lock the owner out at a challenge they cannot answer. Callers run it
 * inside their own transaction.
 */
final readonly class ClaimUnverifiedAccount
{
    public function __construct(private RevokeCredentials $revokeCredentials) {}

    public function __invoke(User $user, #[SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'email_verified_at' => now(),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        ($this->revokeCredentials)($user);
    }
}
