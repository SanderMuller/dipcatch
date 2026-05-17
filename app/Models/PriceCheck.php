<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeStatus;
use Database\Factories\PriceCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCheck extends Model
{
    /** @use HasFactory<PriceCheckFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'in_stock' => 'boolean',
            'checked_at' => 'datetime',
            'status' => ScrapeStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): ?Product
    {
        return $this->shop?->product;
    }
}
