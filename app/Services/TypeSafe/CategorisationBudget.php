<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every paid categorisation call spends one slot, per account and app-wide,
 * within a day. The caps exist so a create-and-delete loop on one account, or
 * a runaway backfill, cannot run up the bill; a denied call leaves the product
 * uncategorised, which the next nightly run or the edit form can repair.
 */
final class CategorisationBudget
{
    private const int DAY = 86400;

    public function allows(User $user): bool
    {
        $userLimit = Config::integer('dipcatch.categories.daily_limit_per_user');
        $appLimit = Config::integer('dipcatch.categories.daily_limit');

        if (($userLimit > 0 && RateLimiter::tooManyAttempts(self::userKey($user), $userLimit))
            || ($appLimit > 0 && RateLimiter::tooManyAttempts(self::appKey(), $appLimit))) {
            return false;
        }

        if ($userLimit > 0) {
            RateLimiter::hit(self::userKey($user), decaySeconds: self::DAY);
        }

        if ($appLimit > 0) {
            RateLimiter::hit(self::appKey(), decaySeconds: self::DAY);
        }

        return true;
    }

    public static function userKey(User $user): string
    {
        return "categorise:user:{$user->id}";
    }

    public static function appKey(): string
    {
        return 'categorise:app';
    }
}
