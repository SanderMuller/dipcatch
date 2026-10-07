<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Drops\DetectDrop;
use App\Enums\LargeDropCheckOutcome;
use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use Carbon\CarbonImmutable;
use Database\Factories\LargeDropCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A large drop read once, and what the shop's next successful reading said.
 * Asked by {@see DetectDrop::confirmLargeDrop()}, answered by
 * {@see CheckShopPrice}, and read by the price changes list. The alert
 * decision does not read it.
 *
 * @property string $id
 * @property string $product_id
 * @property string|null $shop_id
 * @property int $price_check_id
 * @property string $price
 * @property CarbonImmutable $asked_at
 * @property LargeDropCheckOutcome|null $outcome
 * @property int|null $resolved_by_price_check_id
 * @property CarbonImmutable|null $resolved_at
 * @property-read Product $product
 * @property-read Shop|null $shop
 * @property-read PriceCheck $priceCheck
 */
#[WithoutTimestamps]
#[Unguarded]
final class LargeDropCheck extends Model
{
    /** @use HasFactory<LargeDropCheckFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'asked_at' => 'datetime',
            'outcome' => LargeDropCheckOutcome::class,
            'resolved_at' => 'datetime',
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
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<PriceCheck, $this>
     */
    public function priceCheck(): BelongsTo
    {
        return $this->belongsTo(PriceCheck::class);
    }

    /**
     * Answer every open question about this shop with a reading that
     * succeeded. The reading's own price decides, not the product-level
     * verdict: the shop can repeat the low price after another shop became
     * cheaper, and that is still a confirmation.
     */
    public static function answerWith(PriceCheck $reading): void
    {
        if ($reading->status !== ScrapeStatus::Ok || $reading->price === null) {
            return;
        }

        $open = self::query()
            ->where('shop_id', $reading->shop_id)
            ->whereNull('outcome')
            ->where('price_check_id', '<', $reading->id)
            ->get();

        foreach ($open as $check) {
            $held = $reading->in_stock !== false && (float) $reading->price <= (float) $check->price;

            $check->forceFill([
                'outcome' => $held ? LargeDropCheckOutcome::Confirmed : LargeDropCheckOutcome::Rejected,
                'resolved_by_price_check_id' => $reading->id,
                'resolved_at' => $reading->checked_at,
            ])->save();
        }
    }
}
