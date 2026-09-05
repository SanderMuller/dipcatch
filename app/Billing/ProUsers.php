<?php declare(strict_types=1);

namespace App\Billing;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

/**
 * The set of Pro accounts, as a subquery. The scheduler needs "which users
 * are Pro" as SQL, not as a loop over users — the same answer
 * `Subscribes::plan()` gives one user at a time.
 */
final class ProUsers
{
    /**
     * Selects `users.id` for every account currently entitled to Pro.
     */
    public static function ids(): QueryBuilder
    {
        return DB::table('users')
            ->select('users.id')
            ->whereNull('billing_blocked_at')
            ->whereIn('id', Subscription::query()
                ->select('user_id')
                ->where('type', Plan::SUBSCRIPTION_TYPE)
                ->active()
                ->toBase());
    }
}
