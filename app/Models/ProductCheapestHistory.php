<?php declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductCheapestHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCheapestHistory extends Model
{
    /** @use HasFactory<ProductCheapestHistoryFactory> */
    use HasFactory;

    protected $table = 'product_cheapest_history';

    public $timestamps = false;

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
