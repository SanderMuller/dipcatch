<?php declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\ClaimUnverifiedAccount;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final readonly class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private ClaimUnverifiedAccount $claimUnverifiedAccount) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        if ($user->hasVerifiedEmail()) {
            // A verified account keeps its passkeys and two-factor: removing
            // them on reset would let anyone who reads the mailbox past the
            // second factor.
            $user->forceFill([
                'password' => $input['password'],
            ])->save();

            return;
        }

        // The reset link reached the mailbox, which proves ownership the
        // account never had. Marking it verified and revoking what the row
        // held happen together: a verified account with a squatter's old
        // OAuth token would reach MCP again.
        DB::transaction(fn () => ($this->claimUnverifiedAccount)($user, $input['password']));
    }
}
