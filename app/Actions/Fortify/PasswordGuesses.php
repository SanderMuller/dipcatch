<?php declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wrong guesses at a signed-in account's own password, counted per account
 * across every form that asks for it. A stolen session is enough to reach
 * these forms, and the right password unlocks the step-up checks.
 */
final readonly class PasswordGuesses
{
    public const int WRONG_GUESSES = 5;

    public const int DECAY_SECONDS = 900;

    /**
     * The seconds until the account may guess again, or null when it may now.
     */
    public function lockedFor(User $user): ?int
    {
        $key = self::key($user);

        return RateLimiter::tooManyAttempts($key, self::WRONG_GUESSES)
            ? max(1, RateLimiter::availableIn($key))
            : null;
    }

    /**
     * Counts the guess before it checks the hash, so requests sent at once
     * cannot all pass {@see lockedFor()} first and guess past the limit.
     */
    public function check(User $user, string $password): bool
    {
        $key = self::key($user);

        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::WRONG_GUESSES
            || $user->password === null
            || ! Hash::check($password, $user->password)) {
            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    public static function lockedMessage(int $seconds): string
    {
        return __('Too many wrong passwords. Try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)]);
    }

    private static function key(User $user): string
    {
        return 'password-guesses:user:' . $user->id;
    }
}
