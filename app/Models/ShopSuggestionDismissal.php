<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop suggestion the user rejected for one product.
 *
 * @property int $id
 * @property string $product_id
 * @property string $chain
 * @property string $external_id
 * @property CarbonImmutable $dismissed_at
 */
#[WithoutTimestamps]
class ShopSuggestionDismissal extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }
}
