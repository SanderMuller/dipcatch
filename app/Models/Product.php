<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Drops\DetectDrop;
use App\Enums\ShopHealth;
use App\Services\Drops\Reference;
use App\Support\ImageUrl;
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
            'unit_price_target' => 'decimal:2',
            'unit_price_notified' => 'decimal:2',
            'unit_price_notified_at' => 'datetime',
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

    public function isPubliclyShared(): bool
    {
        return is_string($this->share_slug) && $this->share_slug !== '';
    }

    public function publicShareUrl(): ?string
    {
        return $this->isPubliclyShared()
            ? route('product.public', ['slug' => $this->share_slug])
            : null;
    }

    /**
     * Hosts reporting a GTIN that differs from another shop's, when the
     * product's shops disagree. Two different identifiers mean the offers
     * are different articles — a wrong-pack offer would otherwise sit in the
     * comparison unnoticed. Pricing is deliberately left alone: a mismatch
     * is reported, never silently excluded.
     *
     * @return list<string>
     */
    public function mismatchedGtinHosts(): array
    {
        $withGtin = $this->shops->filter(
            static fn (Shop $shop): bool => is_string($shop->gtin) && $shop->gtin !== '',
        );

        if ($withGtin->pluck('gtin')->unique()->count() < 2) {
            return [];
        }

        /** @var list<string> $hosts */
        $hosts = $withGtin->pluck('host')->filter()->unique()->sort()->values()->all();

        return $hosts;
    }

    public function safeImageUrl(): ?string
    {
        return ImageUrl::safe($this->image_url);
    }

    /**
     * Recompute the product's cheapest offer + price. Safe under concurrent
     * CheckShopPrice jobs: locks the product row, writes a new history
     * segment on change, and routes drop detection / latch clear per §5.1.
     *
     * Reference is computed BEFORE the lock so the 30-day window read does
     * not run inside the critical section.
     */
    /**
     * The shop with the lowest price per unit — the best value, which is not
     * always the lowest price: a 370 g bag at EUR 1.99 beats a 200 g bag at
     * EUR 1.69 by a third per kilo.
     *
     * Only shops that state a pack size can take part, and only those
     * sharing one unit: EUR/kg and EUR/piece are not comparable numbers.
     * When the sized shops disagree on the unit, the largest group wins.
     */
    public function bestValueShop(): ?Shop
    {
        $candidates = $this->shops
            ->filter(fn (Shop $shop): bool => $shop->active
                && $shop->current_in_stock
                && $shop->health !== ShopHealth::Dead
                && $shop->unitPrice() !== null);

        if ($candidates->isEmpty()) {
            return null;
        }

        $unit = $candidates->countBy(fn (Shop $shop): string => (string) $shop->pack_unit)
            ->sortDesc()
            ->keys()
            ->first();

        return $candidates
            ->filter(fn (Shop $shop): bool => (string) $shop->pack_unit === $unit)
            // Unit prices are two-decimal strings; compare them as numbers,
            // with the oldest shop winning a tie so the answer is stable.
            ->sortBy([
                fn (Shop $a, Shop $b): int => (float) $a->unitPrice() <=> (float) $b->unitPrice(),
                fn (Shop $a, Shop $b): int => $a->created_at <=> $b->created_at,
            ])
            ->first();
    }

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

        if ($previous === null) {
            return 'down';
        }

        $cmp = bccomp(
            Numeric::str($new),
            Numeric::str($previous),
            self::BC_SCALE,
        );

        return match (true) {
            $cmp < 0 => 'down',
            $cmp > 0 => 'up',
            default => 'unchanged',
        };
    }
}
