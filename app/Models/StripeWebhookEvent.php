<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One row per Stripe event this app has acted on. Stripe redelivers, and a
 * redelivery must not send a second alert or record the same money twice.
 *
 * @property int $id
 * @property string $stripe_id
 * @property string $type
 * @property CarbonImmutable|null $handled_at
 */
#[Unguarded]
class StripeWebhookEvent extends Model
{
    /**
     * Runs the side effects once for this event id, and only once, even if
     * two workers take the same redelivery at the same moment: the unique
     * index decides which insert wins.
     *
     * The work runs inside the claim's transaction, so a failure rolls the
     * claim back and Stripe's next retry can do the whole job again.
     */
    public static function handleOnce(string $stripeId, string $type, callable $work): bool
    {
        if ($stripeId === '') {
            // No event id to key on — an internally dispatched payload in a
            // test, or a Stripe change. Do the work rather than drop it.
            $work();

            return true;
        }

        try {
            return DB::transaction(function () use ($stripeId, $type, $work): bool {
                $claimed = self::query()->create([
                    'stripe_id' => $stripeId,
                    'type' => $type,
                    'handled_at' => now(),
                ]);

                if (! $claimed->exists) {
                    return false;
                }

                $work();

                return true;
            });
        } catch (QueryException $e) {
            if (self::isDuplicate($e)) {
                return false;
            }

            throw $e;
        }
    }

    private static function isDuplicate(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'duplicate key value');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
