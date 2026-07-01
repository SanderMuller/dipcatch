<?php declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductCheapestHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
class ProductCheapestHistory extends Model
{
    /** @use HasFactory<ProductCheapestHistoryFactory> */
    use HasFactory;

    // Larastan needs $table as a property (not #[Table] attribute) to introspect
    // model properties via the migration schema. Don't switch back to #[Table]
    // unless we also update larastan to support attribute-table model discovery.
    protected $table = 'product_cheapest_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheapest_price' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function cheapestShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'cheapest_shop_id');
    }

    /**
     * @return BelongsTo<PriceCheck, $this>
     */
    public function triggeringPriceCheck(): BelongsTo
    {
        return $this->belongsTo(PriceCheck::class, 'triggering_price_check_id');
    }
}
