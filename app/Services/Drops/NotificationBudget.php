<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One hourly ceiling per user, shared by every alert the app sends. The cap
 * exists to stop a buggy product pinging someone all night, so a Pro account
 * gets a higher ceiling, not no ceiling — and an alert type that skipped
 * this class would be a hole in that promise.
 */
final class NotificationBudget
{
    public function allows(User $user): bool
    {
        $limit = $user->entitlements()->notificationsHourlyLimit();

        if ($limit <= 0) {
            return true;
        }

        $key = self::key($user);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        return true;
    }

    public static function key(User $user): string
    {
        return "notify:user:{$user->id}";
    }
}
