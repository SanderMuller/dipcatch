<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\TrackingIdea;
use App\Enums\TrackingIdeaMarkState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A getting-started idea a person ticked by hand.
 *
 * @property int $id
 * @property int $user_id
 * @property TrackingIdea $idea
 * @property TrackingIdeaMarkState $state
 * @property CarbonImmutable $marked_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class TrackingIdeaMark extends Model
{
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
            'idea' => TrackingIdea::class,
            'state' => TrackingIdeaMarkState::class,
            'marked_at' => 'datetime',
        ];
    }
}
