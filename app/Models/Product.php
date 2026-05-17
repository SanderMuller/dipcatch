<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Drops\DetectDrop;
use App\Enums\ShopHealth;
use App\Services\Drops\Reference;
use App\Support\Numeric;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    private const int BC_SCALE = 4;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'drop_threshold_pct' => 'decimal:2',
            'drop_threshold_abs' => 'decimal:2',
            'cheapest_price' => 'decimal:2',
            'last_notified_price' => 'decimal:2',
            'last_notified_at' => 'datetime',
            'active' => 'boolean',
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
     * @return HasMany<Shop, $this>
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function cheapestShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'cheapest_shop_id');
    }

    /**
     * @return HasMany<ProductCheapestHistory, $this>
     */
    public function cheapestHistory(): HasMany
    {
        return $this->hasMany(ProductCheapestHistory::class);
    }

    /**
     * @return HasMany<PriceDropEvent, $this>
     */
    public function priceDropEvents(): HasMany
    {
        return $this->hasMany(PriceDropEvent::class);
    }

    /**
     * Recompute the product's cheapest offer + price. Safe under concurrent
     * CheckShopPrice jobs: locks the product row, writes a new history
     * segment on change, and routes drop detection / latch clear per §5.1.
     *
     * Reference is computed BEFORE the lock so the 30-day window read does
     * not run inside the critical section.
     */
    public function recomputeCheapestShop(?int $triggeringPriceCheckId = null): void
    {
        $reference = app(Reference::class)->compute($this);

        DB::transaction(function () use ($triggeringPriceCheckId, $reference): void {
            $locked = self::query()->lockForUpdate()->find($this->id);

            if ($locked === null) {
                return;
            }

            $previousOfferId = $locked->cheapest_shop_id;
            $previousPrice = $locked->cheapest_price === null
                ? null
                : (string) $locked->cheapest_price;

            /** @var Shop|null $cheapest */
            $cheapest = $locked->shops()
                ->where('active', true)
                ->where('current_in_stock', true)
                ->where('health', '!=', ShopHealth::Dead->value)
                ->whereNotNull('current_price')
                ->orderBy('current_price')
                // Stable tie-break: among equal prices the offer added first
                // wins, with `id` as a final lexicographic guarantee.
                // Without this, the engine picked either row arbitrarily on
                // each recompute, generating spurious
                // `product_cheapest_history` segments and re-anchoring drop
                // detection.
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            $newOfferId = $cheapest?->id;
            $newPrice = $cheapest?->current_price === null
                ? null
                : (string) $cheapest->current_price;

            $locked->forceFill([
                'cheapest_shop_id' => $newOfferId,
                'cheapest_price' => $newPrice,
            ])->save();

            $changed = $previousOfferId !== $newOfferId
                || $previousPrice !== $newPrice;

            if (! $changed) {
                return;
            }

            ProductCheapestHistory::query()
                ->where('product_id', $locked->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            ProductCheapestHistory::create([
                'product_id' => $locked->id,
                'cheapest_shop_id' => $newOfferId,
                'cheapest_price' => $newPrice,
                'started_at' => now(),
                'ended_at' => null,
                'triggering_price_check_id' => $triggeringPriceCheckId,
            ]);

            $direction = self::compareDirection($previousPrice, $newPrice);
            $detector = app(DetectDrop::class);

            match ($direction) {
                'down' => $detector($locked, $triggeringPriceCheckId),
                'up', 'null' => $detector->clearLatchIfRecovered($locked, $newPrice, $reference),
                default => null,
            };
        });

        $this->refresh();
    }

    /**
     * Compare two nullable decimal-string prices.
     * Returns 'down' / 'up' / 'null' / 'unchanged'.
     */
    private static function compareDirection(?string $previous, ?string $new): string
    {
        if ($previous === null && $new === null) {
            return 'unchanged';
        }

        if ($previous !== null && $new === null) {
            return 'null';
        }

        if ($previous === null && $new !== null) {
            return 'down';
        }

        $cmp = bccomp(
            Numeric::str((string) $new),
            Numeric::str((string) $previous),
            self::BC_SCALE,
        );

        return match (true) {
            $cmp < 0 => 'down',
            $cmp > 0 => 'up',
            default => 'unchanged',
        };
    }
}
