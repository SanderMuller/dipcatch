<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the daily checkjebon.nl price dataset — an internal cache
 * table owned by RefreshCheckjebonDatasetCommand, read by CheckjebonSource.
 * `supermarket` is the dataset key ('ah' | 'dirk' | 'lidl'); `external_id`
 * is the AH `wi` id or the Dirk/boodschaapje numeric product id.
 *
 * @property int $id
 * @property string $supermarket
 * @property string $external_id
 * @property string $name
 * @property numeric-string $price
 * @property string|null $size
 * @property CarbonImmutable $refreshed_at
 */
#[WithoutTimestamps]
class CheckjebonPrice extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'refreshed_at' => 'datetime',
        ];
    }
}
