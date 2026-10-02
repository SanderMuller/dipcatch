<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether one supermarket list row's product page is on the shop's site.
 * A row with no record has not been checked, and is offered as before.
 *
 * @property int $id
 * @property string $chain
 * @property string $external_id
 * @property bool $alive
 * @property string|null $url
 * @property CarbonImmutable $checked_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class CatalogueLink extends Model
{
    /**
     * A check holds this long. A page gone after one 404 may be back after a
     * shop's deploy, so an old answer counts as no answer.
     */
    public const int VALID_DAYS = 30;

    public static function validFrom(): CarbonImmutable
    {
        return now()->subDays(self::VALID_DAYS)->toImmutable();
    }

    public static function record(string $chain, string $externalId, bool $alive, ?string $url = null): void
    {
        self::query()->upsert(
            [['chain' => $chain, 'external_id' => $externalId, 'alive' => $alive, 'url' => $url, 'checked_at' => now()]],
            ['chain', 'external_id'],
            ['alive', 'url', 'checked_at'],
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alive' => 'boolean',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
