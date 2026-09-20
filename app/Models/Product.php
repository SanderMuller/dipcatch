<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Drops\DetectDrop;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Enums\ShopHealth;
use App\Services\Drops\Reference;
use App\Support\ComparablePacks;
use App\Support\ImageUrl;
use App\Support\Numeric;
use App\Support\PackSize;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property ProductCategory|null $category
 * @property CategorySource|null $category_set_by
 * @property ProductCategory|null $suggested_category
 * @property CarbonImmutable|null $history_kept_from
 * @property-read PriceDropEvent|null $latestPriceDropEvent
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
            'best_value_price' => 'decimal:2',
            'best_value_pack_quantity' => 'decimal:2',
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
            'suggested_category' => ProductCategory::class,
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
     * The shop with the lowest price per unit — the better deal, and the basis a
     * drop is decided on. Kept apart from {@see cheapestShop()}, which stays the
     * smallest amount of money handed over.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function bestValueShopRelation(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'best_value_shop_id');
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
     * @return HasOne<PriceDropEvent, $this>
     */
    public function latestPriceDropEvent(): HasOne
    {
        // Not ofMany(): that aggregates MAX over the uuid key, which Postgres refuses.
        return $this->hasOne(PriceDropEvent::class)->latest('fired_at')->latest('id');
    }

    /**
     * The drop the product is still in: the alert that last fired, while the
     * price has not recovered. `last_notified_price` is the latch DetectDrop
     * clears on recovery, so the latest event is the current drop only while
     * the latch is set.
     */
    public function activeDrop(): ?PriceDropEvent
    {
        if ($this->last_notified_price === null) {
            return null;
        }

        return $this->latestPriceDropEvent;
    }

    /**
     * How far the current cheapest price sits below the reference the alert
     * fired from, in whole percent. The latch holds until the price is back
     * at the reference, so the price can have climbed since the alert; the
     * event's own percentage is only the fallback when no price is known.
     */
    public function activeDropPercent(): ?int
    {
        $drop = $this->activeDrop();

        if ($drop === null) {
            return null;
        }

        // Both sides in the basis the alert fired on. Mixing an event's unit
        // reference with today's pack price answers a question nobody asked and
        // gets the badge wrong by whatever the pack size is.
        $unit = is_string($drop->comparison_unit) ? $drop->comparison_unit : null;
        $referenceValue = $unit === null ? $drop->reference_price : $drop->reference_unit_price;
        $current = $this->dropBasisPrice($unit);

        if ($referenceValue === null) {
            return (int) round((float) $drop->drop_pct);
        }

        $reference = Numeric::str((string) $referenceValue);

        if ($current === null || bccomp($reference, '0', self::BC_SCALE) <= 0) {
            return (int) round((float) $drop->drop_pct);
        }

        $fraction = bcdiv(bcsub($reference, Numeric::str($current), self::BC_SCALE), $reference, self::BC_SCALE);

        return max(0, (int) round((float) $fraction * 100));
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

    /**
     * The resolver for this product's shops: which of them can be compared per
     * unit, on what size, and why the others cannot.
     */
    public function comparablePacks(): ComparablePacks
    {
        // Every shop gets an answer; only the sellable ones get a vote on what
        // this product is measured in. See {@see ComparablePacks::of()}.
        return ComparablePacks::of($this->shops, (string) $this->currency, $this->eligibleShops());
    }

    /**
     * The shop with the lowest price per unit — the better deal.
     *
     * Computed live rather than read from the column so a caller that has not
     * recomputed still gets an answer; {@see recomputeCheapestShop()} stores the
     * same value under the row lock, and both go through
     * {@see ComparablePacks} so there is one definition of who is eligible.
     */
    public function bestValueShop(): ?Shop
    {
        return self::bestValueAmong($this->eligibleShops(), $this->comparablePacks());
    }

    /**
     * Shops allowed to win either answer: active, not dead, priced, in this
     * product's own currency.
     *
     * Unknown stock still competes — the price is real, and dropping it would
     * hide a shop rather than describe it.
     *
     * @return Collection<int, Shop>
     */
    private function eligibleShops(): Collection
    {
        return $this->shops->filter(fn (Shop $shop): bool => $shop->active
            && $shop->current_in_stock !== false
            && $shop->health !== ShopHealth::Dead
            && $shop->currency === $this->currency
            && $shop->current_price !== null);
    }

    /**
     * @param  Collection<int, Shop>  $candidates
     */
    private static function bestValueAmong(Collection $candidates, ComparablePacks $packs): ?Shop
    {
        return $packs->cheapestPerUnit($candidates);
    }

    /** The best-value winner's own pack size, as last recomputed. */
    public function bestValuePackSize(): ?PackSize
    {
        if ($this->best_value_pack_quantity === null || ! is_string($this->best_value_pack_unit)) {
            return null;
        }

        return PackSize::of((float) $this->best_value_pack_quantity, $this->best_value_pack_unit);
    }

    /**
     * The winning price in the basis a drop is measured in: per unit while the
     * product has a comparison unit, per pack otherwise.
     *
     * One accessor rather than a branch at each call site, because the whole
     * detection path — direction, reference, recovery, the duplicate latch —
     * has to agree on the basis or a fall in one reads as a rise in the other.
     */
    public function dropBasisPrice(?string $unit): ?string
    {
        if ($unit === null) {
            return $this->cheapest_price === null ? null : (string) $this->cheapest_price;
        }

        return $this->best_value_price === null
            ? null
            : $this->bestValuePackSize()?->unitPriceFor((string) $this->best_value_price);
    }

    /** The pack price of whichever shop the basis is measured on. */
    public function winningPackPrice(?string $unit): ?string
    {
        $price = $unit === null ? $this->cheapest_price : $this->best_value_price;

        return $price === null ? null : (string) $price;
    }

    /**
     * @param  Collection<int, Shop>  $candidates
     */
    private static function lowestOutlayAmong(Collection $candidates): ?Shop
    {
        return $candidates
            ->sort(fn (Shop $a, Shop $b): int => [(float) $a->current_price, $a->created_at, (string) $a->id]
                <=> [(float) $b->current_price, $b->created_at, (string) $b->id])
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

            // Ordering happens in PHP rather than SQL: a unit price is not a
            // column, and a size inherited from siblings is not on the row at
            // all. The tie-break moves with it — among equal prices the offer
            // added first wins, with `id` as a final lexicographic guarantee.
            // Without it the engine picked either row arbitrarily on each
            // recompute, generating spurious `product_cheapest_history`
            // segments and re-anchoring drop detection.
            $locked->setRelation('shops', $locked->shops()->get());
            $candidates = $locked->eligibleShops();
            $packs = $locked->comparablePacks();

            $cheapest = self::lowestOutlayAmong($candidates);
            $bestValue = self::bestValueAmong($candidates, $packs);
            $bestValueSize = $bestValue === null ? null : $packs->for($bestValue)?->size;

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

            $previousBestValueId = $locked->best_value_shop_id;
            $previousBasis = $locked->dropBasisPrice($packs->unit());
            $previousBestValuePack = $locked->winningPackPrice($packs->unit());
            $previousBestValueSize = $locked->bestValuePackSize();
            $bestValuePrice = $bestValue?->current_price === null
                ? null
                : (string) $bestValue->current_price;

            $locked->forceFill([
                'cheapest_shop_id' => $newOfferId,
                'cheapest_price' => $newPrice,
                'best_value_shop_id' => $bestValue?->id,
                'best_value_price' => $bestValuePrice,
                'best_value_pack_quantity' => $bestValueSize?->quantity,
                'best_value_pack_unit' => $bestValueSize?->unit,
            ])->save();

            // The reference was computed before the lock, and a sibling check on
            // another shop can change a pack size in between — enough to flip
            // the product's comparison unit. Evaluating a pack-basis reference
            // against a unit-basis price is a category error, so a mismatched
            // pair skips detection entirely and the next recompute gets a
            // consistent one.
            if ($reference !== null && $reference->unit !== $packs->unit()) {
                $reference = null;
            }

            $newBasis = $locked->dropBasisPrice($packs->unit());
            $newBasisPack = $locked->winningPackPrice($packs->unit());

            // Someone correcting a pack size is not a price moving. The same
            // shop, the same money, a different amount: the unit price changes
            // by whatever the correction was, which reads as a spectacular drop
            // or rise and is neither. The segment is still written — history
            // has to stay faithful — but nothing is detected on it.
            $sizeCorrection = $previousBestValueId !== null
                && $previousBestValueId === $bestValue?->id
                && $previousBestValuePack === $newBasisPack
                && $previousBestValueSize?->isSameSizeAs($bestValueSize) === false;

            // Before the `$changed` gate on purpose: a confirming reading is
            // the same price again, so it changes nothing and never reaches
            // the detector call at the bottom of this transaction.
            if ($reference !== null && $newBasis !== null && $newOfferId !== null && ! $sizeCorrection) {
                app(DetectDrop::class)->confirmLargeDrop($locked, $newBasis, $triggeringPriceCheckId, $reference);
            }

            $changed = $previousOfferId !== $newOfferId
                || $previousBestValueId !== $bestValue?->id
                || $previousBestValuePack !== $newBasisPack
                || $previousBestValueSize?->isSameSizeAs($bestValueSize) === false
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
                'best_value_shop_id' => $bestValue?->id,
                'best_value_price' => $bestValuePrice,
                'pack_quantity' => $bestValueSize?->quantity,
                'pack_unit' => $bestValueSize?->unit,
                'single_item_price' => $singleItemPrice,
                'bundle_quantity' => $bundleOffer?->quantity,
                'bundle_total_price' => $bundleOffer?->totalPrice,
                'started_at' => now(),
                'ended_at' => null,
                'triggering_price_check_id' => $triggeringPriceCheckId,
            ]);

            if ($sizeCorrection) {
                return;
            }

            // Direction is read in the drop's own basis. On the pack basis it
            // is the old question; on the unit basis a bigger pack that costs
            // more money can still be the better deal, and asking the pack
            // price would send that fall down the recovery branch instead.
            $direction = self::compareDirection($previousBasis, $newBasis);
            $detector = app(DetectDrop::class);

            match ($direction) {
                'down' => $detector($locked, $triggeringPriceCheckId, $reference),
                'up', 'null' => $detector->clearLatchIfRecovered($locked, $newBasis, $reference),
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
