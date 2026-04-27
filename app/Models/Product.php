<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fallback_selectors' => 'array',
            'initial_price' => 'decimal:2',
            'last_price' => 'decimal:2',
            'drop_threshold_pct' => 'decimal:2',
            'drop_threshold_abs' => 'decimal:2',
            'last_notified_price' => 'decimal:2',
            'initial_checked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'needs_js' => 'boolean',
            'active' => 'boolean',
            'last_status' => ScrapeStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PriceCheck, $this>
     */
    public function priceChecks(): HasMany
    {
        return $this->hasMany(PriceCheck::class);
    }

    /**
     * @return HasMany<PriceDropEvent, $this>
     */
    public function priceDropEvents(): HasMany
    {
        return $this->hasMany(PriceDropEvent::class);
    }
}
