<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $email
 * @property string $token
 * @property int $invited_by
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable $expires_at
 * @property User $inviter
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function isExpired(): bool
    {
        return (bool) $this->expires_at->isPast();
    }
}
