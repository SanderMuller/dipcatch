<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A search in the header that found nothing, counted per person and term.
 * Kept for six months after the last time it was searched.
 *
 * @property int $id
 * @property int $user_id
 * @property string $term
 * @property int $times
 * @property CarbonImmutable $first_searched_at
 * @property CarbonImmutable $last_searched_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class EmptySearch extends Model
{
    use MassPrunable;

    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 100;

    /** Lowercased, trimmed, with runs of spaces made one; null when too short to mean much. */
    public static function normalize(string $term): ?string
    {
        $term = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term));

        return mb_strlen($term) < self::MIN_LENGTH ? null : mb_substr($term, 0, self::MAX_LENGTH);
    }

    public static function record(User $user, string $term): void
    {
        self::query()->upsert(
            [['user_id' => $user->id, 'term' => $term, 'times' => 1, 'first_searched_at' => now(), 'last_searched_at' => now()]],
            ['user_id', 'term'],
            ['times' => DB::raw('empty_searches.times + 1'), 'last_searched_at' => now()],
        );
    }

    /**
     * @return EloquentQueryBuilder<self>
     */
    public function prunable(): EloquentQueryBuilder
    {
        return self::query()->where('last_searched_at', '<=', now()->subMonths(6));
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
            'times' => 'integer',
            'first_searched_at' => 'datetime',
            'last_searched_at' => 'datetime',
        ];
    }
}
