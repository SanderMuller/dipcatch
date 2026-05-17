<?php declare(strict_types=1);

namespace App\Models;

use Database\Factories\PriceDropEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceDropEvent extends Model
{
    /** @use HasFactory<PriceDropEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'drop_pct' => 'decimal:4',
            'drop_abs' => 'decimal:2',
            'fired_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PriceCheck, $this>
     */
    public function priceCheck(): BelongsTo
    {
        return $this->belongsTo(PriceCheck::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function triggeredByShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'triggered_by_shop_id');
    }
}
