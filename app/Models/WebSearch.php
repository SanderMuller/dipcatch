<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One web search and its organic results, shared by every product whose
 * title makes the same query for the same country.
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
    /**
     * The country is part of the key: one query searched for another
     * country is another search. A Dutch search keeps the hash it had
     * before searches had a country, so the stored ones stay valid.
     */
    public static function hashOf(string $query, string $country): string
    {
        $normalised = self::normalise($query);

        return hash('sha256', $country === 'nl' ? $normalised : "gl:{$country} {$normalised}");
    }

    public static function normalise(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? $query));
    }

    /**
     * Whether the query looks one product up rather than searching its
     * title: on one shop's site, or by its barcode. Findings from such a
     * search are not in the title search, so a refreshed title search must
     * not delete them.
     */
    public static function isLookup(string $query): bool
    {
        return str_starts_with($query, 'site:') || ctype_digit($query);
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
