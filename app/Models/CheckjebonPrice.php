<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the suggestion catalogue: the daily checkjebon.nl dataset,
 * written by RefreshCheckjebonDatasetCommand and read by CheckjebonSource,
 * and bol.com offers (`supermarket` 'bol'), written through BolCatalogRows.
 * A prune or reset of one source must not touch the other's rows.
 * `supermarket` is the dataset key ('ah', 'jumbo', 'plus', …); `external_id`
 * is the AH `wi` id, the boodschaapje numeric id, or — for the match-only
 * chains — the raw link. `link` is always the raw upstream link, appended to
 * the chain's base URL to build a product URL.
 *
 * @property int $id
 * @property string $supermarket
 * @property string $external_id
 * @property string $name
 * @property numeric-string $price
 * @property string|null $size
 * @property string|null $link
 * @property string|null $ean  Barcode with leading zeros stripped; only bol.com rows carry one.
 * @property CarbonImmutable $refreshed_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class CheckjebonPrice extends Model
{
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
