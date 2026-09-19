<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Drops\DetectDrop;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Enums\ShopHealth;
use App\Services\Drops\Reference;
use App\Support\ImageUrl;
use App\Support\Numeric;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property ProductCategory|null $category
 * @property CategorySource|null $category_set_by
 * @property CarbonImmutable|null $history_kept_from
 */
#[Unguarded]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    private const int BC_SCALE = 4;

    protected static function booted(): void
    {
        // `updating` only, not `saving`: it never fires on insert, so a
        // fresh row's own target and latch (set together, e.g. by a
        // factory) survive untouched.
        self::updating(function (self $product): void {
            if ($product->isDirty('target_price')) {
                $product->target_price_notified = null;
                $product->target_price_notified_at = null;
            }

            if ($product->isDirty('unit_price_target')) {
                $product->unit_price_notified = null;
                $product->unit_price_notified_at = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'drop_threshold_pct' => 'decimal:2',
            'drop_threshold_abs' => 'decimal:2',
            'cheapest_price' => 'decimal:2',
            'target_price' => 'decimal:2',
            'target_price_notified' => 'decimal:2',
            'target_price_notified_at' => 'datetime',
            'unit_price_target' => 'decimal:2',
            'unit_price_notified' => 'decimal:2',
            'unit_price_notified_at' => 'datetime',
            'last_notified_price' => 'decimal:2',
            'last_notified_at' => 'datetime',
            'history_kept_from' => 'datetime',
            'active' => 'boolean',
            'category' => ProductCategory::class,
            'category_set_by' => CategorySource::class,
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
    /**
     * The unit the unit-price comparison runs in — `g`, `ml` or `piece` — or
     * null while no shop has read a pack size.
     *
     * Shops can disagree: one reporting pieces while the rest report grams is
     * ordinary, and {@see bestValueShop()} resolves it by comparing only
     * inside the largest group. This answers with that same group's unit, so
     * a screen can name the unit the alert will actually use instead of
     * offering the reader a choice it does not have.
     *
     * Not filtered by stock or health, unlike best value: a label describes
     * the product, and it should not change because a shop went out of stock.
     */
    public function unitPriceUnit(): ?string
    {
        $counts = [];

        foreach ($this->shops as $shop) {
            if (is_string($shop->pack_unit) && $shop->pack_unit !== '') {
                $counts[$shop->pack_unit] = ($counts[$shop->pack_unit] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        // Most shops win; an even split falls back to the alphabetically
        // first unit, so the same product always answers the same way.
        ksort($counts);
        arsort($counts);

        return array_key_first($counts);
    }

    public function bestValueShop(): ?Shop
    {
        $candidates = $this->shops
            ->filter(fn (Shop $shop): bool => $shop->active
                // Unknown stock still competes: the price is real, and
                // dropping it would hide a shop rather than describe it.
                && $shop->current_in_stock !== false
                && $shop->health !== ShopHealth::Dead
                && $shop->currency === $this->currency
                && $shop->unitPrice() !== null);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Group by unit AND currency: a EUR/kg figure and a (drifted) GBP/kg
        // figure are not comparable numbers even though the unit matches.
        $group = $candidates->countBy(fn (Shop $shop): string => $shop->pack_unit . '|' . $shop->currency)
            ->sortDesc()
            ->keys()
            ->first();

        return $candidates
            ->filter(fn (Shop $shop): bool => $shop->pack_unit . '|' . $shop->currency === $group)
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
        // Computed before the lock and handed to both branches below: the
        // 30-day window read is a segment query plus a price_checks count,
        // and it has no business inside the critical section.
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
                ->where(fn (EloquentBuilder $stock): EloquentBuilder => $stock
                    ->where('current_in_stock', true)
                    ->orWhereNull('current_in_stock'))
                ->where('health', '!=', ShopHealth::Dead->value)
                ->where('currency', $locked->currency)
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
            $singleItemPrice = $cheapest?->singleItemPrice();
            $bundleOffer = $cheapest?->liveBundleOffer();

            $openSegment = ProductCheapestHistory::query()
                ->where('product_id', $locked->id)
                ->whereNull('ended_at')
                ->latest('id')
                ->first();

            $locked->forceFill([
                'cheapest_shop_id' => $newOfferId,
                'cheapest_price' => $newPrice,
            ])->save();

            // Before the `$changed` gate on purpose: a confirming reading is
            // the same price again, so it changes nothing and never reaches
            // the detector call at the bottom of this transaction.
            if ($reference !== null && $newPrice !== null && $newOfferId !== null) {
                app(DetectDrop::class)->confirmLargeDrop($locked, $newPrice, $triggeringPriceCheckId, $reference);
            }

            $changed = $previousOfferId !== $newOfferId
                || $previousPrice !== $newPrice
                || $openSegment?->singleItemPrice() !== $singleItemPrice
                || $openSegment?->bundleOffer()?->quantity !== $bundleOffer?->quantity
                || $openSegment?->bundleOffer()?->totalPrice !== $bundleOffer?->totalPrice;

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
                'single_item_price' => $singleItemPrice,
                'bundle_quantity' => $bundleOffer?->quantity,
                'bundle_total_price' => $bundleOffer?->totalPrice,
                'started_at' => now(),
                'ended_at' => null,
                'triggering_price_check_id' => $triggeringPriceCheckId,
            ]);

            $direction = self::compareDirection($previousPrice, $newPrice);
            $detector = app(DetectDrop::class);

            match ($direction) {
                'down' => $detector($locked, $triggeringPriceCheckId, $reference),
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
