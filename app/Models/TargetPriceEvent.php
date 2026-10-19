<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\PackProvenance;
use App\Support\BundlePriceLabel;
use App\Support\ItemPricing;
use App\Support\PackSize;
use App\Support\PriceVerdicts;
use Carbon\CarbonImmutable;
use Database\Factories\TargetPriceEventFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * A product reaching the price its owner set, as the daily digest reads it.
 * The push and the bell go out when it happens.
 *
 * @property string|null $shop_id
 * @property bool|null $lowest_in_90_days Whether the shop's price was its lowest in 90 days when this fired; null on rows written before the column existed.
 * @property CarbonImmutable $fired_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class TargetPriceEvent extends Model
{
    /** @use HasFactory<TargetPriceEventFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'decimal:4',
            'price' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'pack_quantity' => 'decimal:2',
            'item_size' => 'float',
            'lowest_in_90_days' => 'boolean',
            'fired_at' => 'datetime',
        ];
    }

    /**
     * Writes the row for a target just reached, with the shop's pack and any
     * multi-buy it takes as they stand now: the digest reads them hours later.
     * A unit price marks the price-per-unit target; without one it is the
     * pack-price target.
     */
    public static function record(
        Product $product,
        Shop $shop,
        string $target,
        ?string $price,
        ?string $unitPrice = null,
    ): self {
        // The size the shop was compared by when the target was reached,
        // which can be the second size it states, 560 g beside 20 pieces.
        // Not an estimate made from the shop's own size in the other unit:
        // the event keeps what the shop itself said.
        $pack = $product->comparablePacks()->for($shop);
        $packSize = ($pack?->provenance === PackProvenance::Converted ? null : $pack?->size) ?? $shop->comparableSize();
        $bundle = $shop->liveBundleOffer();

        return self::query()->create([
            'product_id' => $product->id,
            'user_id' => $product->user_id,
            'shop_id' => $shop->id,
            'currency' => $product->currency,
            'target' => $target,
            'price' => $price,
            'unit_price' => $unitPrice,
            'comparison_unit' => $unitPrice === null ? null : $packSize?->unit,
            'pack_quantity' => $packSize?->quantity,
            'pack_unit' => $packSize?->unit,
            'deal' => $bundle === null ? null : BundlePriceLabel::condition($bundle, $product->currency),
            'lowest_in_90_days' => PriceVerdicts::isLowestIn90Days($shop),
            'item_size' => $unitPrice === null ? null : ItemPricing::itemSizeOf($product),
            'fired_at' => now(),
        ]);
    }

    /**
     * Whether this target was already reached at this price or lower within
     * `dipcatch.drops.resend_after_days`. A unit price, with its unit, asks
     * about the price-per-unit target; without one, about the pack-price
     * target. A changed target is a new question, so it matches only its own.
     */
    public static function alreadySent(Product $product, string $target, string $price, ?string $unit = null): bool
    {
        return self::query()
            ->where('user_id', $product->user_id)
            ->where('fired_at', '>=', now()->subDays(Config::integer('dipcatch.drops.resend_after_days')))
            ->where('product_id', $product->id)
            ->where('target', $target)
            ->when(
                $unit === null,
                fn (Builder $query): Builder => $query->whereNull('unit_price')->where('price', '<=', $price),
                fn (Builder $query): Builder => $query->where('comparison_unit', $unit)->where('unit_price', '<=', $price),
            )
            ->exists();
    }

    public function isPerUnit(): bool
    {
        return $this->unit_price !== null;
    }

    public function packSize(): ?PackSize
    {
        if ($this->pack_quantity === null || ! is_string($this->pack_unit)) {
            return null;
        }

        return PackSize::of((float) $this->pack_quantity, $this->pack_unit);
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
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
