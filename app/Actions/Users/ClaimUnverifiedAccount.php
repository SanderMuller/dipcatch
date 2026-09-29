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
 * would lock the owner out at a challenge they cannot answer.
 *
 * The data on the row is not evidence either way. An unverified account can
 * hold products and a paid subscription: a verified account that changes its
 * address becomes unverified, and a real owner who did that and then resets
 * their password lands here too. So products, settings and billing stay, and
 * the owner can remove them. Public share links do not: a squatter's product
 * would stay published under the owner's account without their knowing.
 *
 * Callers run it inside their own transaction.
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

        $user->products()->whereNotNull('share_slug')->update(['share_slug' => null]);
    }
}
