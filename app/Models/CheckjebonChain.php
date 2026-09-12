<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-chain metadata from the checkjebon dataset: the base URL a product
 * link is appended to, and the display name. Owned by
 * RefreshCheckjebonDatasetCommand, read when a suggestion is rendered.
 *
 * @property int $id
 * @property string $chain
 * @property string $label
 * @property string $base_url
 * @property CarbonImmutable $refreshed_at
 */
#[WithoutTimestamps]
class CheckjebonChain extends Model
{
    protected $guarded = [];

    public function productUrl(string $link): string
    {
        return $this->base_url . $link;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refreshed_at' => 'datetime',
        ];
    }
}
