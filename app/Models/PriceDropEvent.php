<?php declare(strict_types=1);

namespace App\Models;

use App\Support\MoneyFormatter;
use App\Support\PackSize;
use App\Support\UnitWord;
use Database\Factories\PriceDropEventFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Unguarded]
final class PriceDropEvent extends Model
{
    /** @use HasFactory<PriceDropEventFactory> */
    use HasFactory, HasUuids;

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
            // Four. `reference_price` and `new_price` above stay at two —
            // they are money a till charges, these are a rate.
            'reference_unit_price' => 'decimal:4',
            'new_unit_price' => 'decimal:4',
            'pack_quantity' => 'decimal:2',
            'fired_at' => 'datetime',
        ];
    }

    /** The pack the drop was measured on; null on a pack-basis event or one written before it was stored. */
    public function packSize(): ?PackSize
    {
        if ($this->pack_quantity === null || ! is_string($this->pack_unit)) {
            return null;
        }

        return PackSize::of((float) $this->pack_quantity, $this->pack_unit);
    }

    /**
     * "Was €9.99": the price this alert measured the drop from, in pack money.
     *
     * Pack money on purpose, and safe now that the unit figures have columns of
     * their own — this is the number the shopper handed over, and a price per
     * kilo under a "Was" label would be a different claim.
     */
    public function wasLabel(): string
    {
        // Pack money when there is any. A drop measured across two pack sizes
        // has none — the reference and the winner sold different amounts — so
        // the label states the per-unit figure the alert actually fired on,
        // with its unit, rather than printing a kilo price as a till price.
        if ($this->reference_price !== null) {
            return __('Was :price', ['price' => MoneyFormatter::format((string) $this->reference_price, $this->currency)]);
        }

        $unitPrice = $this->reference_unit_price;

        if ($unitPrice === null) {
            return __('Was :price', ['price' => MoneyFormatter::format(amount: null, currency: $this->currency)]);
        }

        return __('Was :price', [
            'price' => MoneyFormatter::unitPrice((string) $unitPrice, $this->currency)
                . ' ' . UnitWord::labelFor(is_string($this->comparison_unit) ? $this->comparison_unit : null),
        ]);
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
