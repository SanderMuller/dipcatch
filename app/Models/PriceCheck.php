<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\BundleOffer;
use Carbon\CarbonImmutable;
use Database\Factories\PriceCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $shop_id
 * @property string|null $price
 * @property string|null $single_item_price
 * @property int|null $bundle_quantity
 * @property string|null $bundle_total_price
 * @property string|null $currency
 * @property bool|null $in_stock
 * @property ScrapeStatus $status
 * @property string|null $error
 * @property CarbonImmutable $checked_at
 * @property-read Shop $shop
 */
#[WithoutTimestamps]
#[Unguarded]
final class PriceCheck extends Model
{
    /** @use HasFactory<PriceCheckFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'single_item_price' => 'decimal:2',
            'bundle_quantity' => 'integer',
            'bundle_total_price' => 'decimal:2',
            'in_stock' => 'boolean',
            'checked_at' => 'datetime',
            'status' => ScrapeStatus::class,
        ];
    }

    /**
     * A reading that can stand as an observation of the shop's price: it
     * succeeded, it carries a price, and the offer was not out of stock.
     * `recomputeCheapestShop()` excludes an out-of-stock offer from cheapest
     * selection, so such a reading never had a drop of its own and must not
     * confirm another one.
     *
     * @param EloquentQueryBuilder<PriceCheck> $query
     */
    #[Scope]
    protected function eligible(EloquentQueryBuilder $query): void
    {
        $query->where('status', ScrapeStatus::Ok)
            ->whereNotNull('price')
            ->where(fn (EloquentQueryBuilder $stock): EloquentQueryBuilder => $stock
                ->where('in_stock', true)
                ->orWhereNull('in_stock'));
    }

    public function isEligible(): bool
    {
        return $this->status === ScrapeStatus::Ok
            && $this->price !== null
            && $this->in_stock !== false;
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

    public function singleItemPrice(): ?string
    {
        $price = $this->single_item_price ?? $this->price;

        return $price === null ? null : (string) $price;
    }

    public function bundleOffer(): ?BundleOffer
    {
        return BundleOffer::stored(
            $this->bundle_quantity,
            $this->bundle_total_price,
            $this->singleItemPrice(),
        );
    }
}
