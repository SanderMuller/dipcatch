<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StripePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A settled money movement mirrored from Stripe. `amount` is in the
 * currency's minor unit, negative for a refund, so a sum over the rows is
 * the net revenue.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $stripe_id
 * @property string $kind
 * @property int $amount
 * @property string $currency
 * @property CarbonImmutable $occurred_at
 * @property-read User|null $user
 */
#[Unguarded]
class StripePayment extends Model
{
    /** @use HasFactory<StripePaymentFactory> */
    use HasFactory;

    public const string KIND_PAYMENT = 'payment';

    public const string KIND_REFUND = 'refund';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
