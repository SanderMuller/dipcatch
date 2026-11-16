<?php declare(strict_types=1);

namespace App\Rules;

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in account's own password, with wrong guesses limited.
 */
final readonly class CurrentPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();
        $guesses = app(PasswordGuesses::class);

        if (! $user instanceof User || ! is_string($value)) {
            $fail('validation.current_password')->translate();

            return;
        }

        $lockedFor = $guesses->lockedFor($user);

        if ($lockedFor !== null) {
            $fail(PasswordGuesses::lockedMessage($lockedFor));

            return;
        }

        if (! $guesses->check($user, $value)) {
            $fail('validation.current_password')->translate();
        }
    }
}
