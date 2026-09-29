<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every paid Jev call spends one slot, per account and app-wide, within a
 * day: categorisation and each kind of shop check on counters of their own. The caps exist so a create-and-delete loop on one account, or
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

    /**
     * The same guard for the same-product checks, on counters of their own
     * per purpose, so checking shops never spends the categorisation budget
     * and background suggestion checks never spend the add-shop one.
     */
    public function allowsShopCheck(User $user, ShopCheckPurpose $purpose): bool
    {
        // Counted first and compared after, so two requests at once cannot
        // both pass a check that neither has counted yet.
        return self::spend("shop-check:{$purpose->value}:user:{$user->id}", Config::integer('dipcatch.shop_checks.daily_limit_per_user'))
            && self::spend("shop-check:{$purpose->value}:app", Config::integer('dipcatch.shop_checks.daily_limit'));
    }

    private static function spend(string $key, int $limit): bool
    {
        return $limit <= 0 || RateLimiter::hit($key, decaySeconds: self::DAY) <= $limit;
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
