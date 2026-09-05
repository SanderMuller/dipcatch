<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StripeDisputeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chargeback mirrored from Stripe. Rows are the admin dispute queue and
 * the record of why an account was blocked.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $stripe_id
 * @property string|null $stripe_charge_id
 * @property int $amount
 * @property string $currency
 * @property string|null $reason
 * @property string $status
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property-read User|null $user
 */
class StripeDispute extends Model
{
    /** @use HasFactory<StripeDisputeFactory> */
    use HasFactory;

    public const string STATUS_LOST = 'lost';

    public const string STATUS_WON = 'won';

    protected $guarded = [];

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isLost(): bool
    {
        return $this->status === self::STATUS_LOST;
    }

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
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
