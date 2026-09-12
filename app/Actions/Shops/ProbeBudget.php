<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many pages one account may fetch per minute, across every path that
 * fetches one — adding a shop, creating a product, and asking for a recheck.
 *
 * One budget rather than one per tool: the shops do not care which tool
 * asked, and a caller that could spend the budget twice would be throttled
 * by the shops instead of by us.
 */
final readonly class ProbeBudget
{
    /** Public so a caller-facing message can state the number. */
    public const int PER_MINUTE = 6;

    /**
     * Take one page from this account's budget. Returns null when it was
     * given, or the seconds to wait when it was not.
     */
    public function spend(User $user): ?int
    {
        $key = self::key($user);

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return max(1, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key);

        return null;
    }

    private static function key(User $user): string
    {
        return "dipcatch:probe:user:{$user->id}";
    }
}
