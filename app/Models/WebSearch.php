<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One web search and its organic results, shared by every product whose
 * title makes the same query.
 *
 * @property int $id
 * @property string $query_hash
 * @property string $query
 * @property list<array{title: string, link: string, snippet: string, position: int}> $results
 * @property CarbonImmutable $searched_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class WebSearch extends Model
{
    public static function hashOf(string $query): string
    {
        return hash('sha256', self::normalise($query));
    }

    public static function normalise(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? $query));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'results' => 'array',
            'searched_at' => 'datetime',
        ];
    }
}
