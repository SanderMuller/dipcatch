<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Jev's chance that one suggested dataset row sells the same product, in
 * the same pack, as the product it was suggested for.
 *
 * @property int $id
 * @property string $product_id
 * @property string $chain
 * @property string $external_id
 * @property string $fingerprint
 * @property float $same_chance
 * @property CarbonImmutable $checked_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class ShopSuggestionVerdict extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'same_chance' => 'float',
            'checked_at' => 'datetime',
        ];
    }
}
