<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsumerPriceIssue;
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
 * @property string|null $claimed_regular_price The price the page said it charged before the discount.
 * @property string|null $seller The marketplace seller the page named for the offer.
 * @property bool|null $claim_read True when the reader can state a claim, so null means the page stated none.
 * @property bool|null $shelf_inherited True when the prices were carried over from an earlier reading.
 * @property ConsumerPriceIssue|null $consumer_price_issue Why this price is not one a shopper can pay.
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
            'claimed_regular_price' => 'decimal:2',
            'claim_read' => 'boolean',
            'shelf_inherited' => 'boolean',
            'consumer_price_issue' => ConsumerPriceIssue::class,
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

    /**
     * True when this reading is a shop joining a product that was already
     * being watched elsewhere.
     *
     * Two things have to hold. The shop has no earlier usable reading, so
     * nothing of its own has fallen; and another shop on the same product
     * does, so the reference describes a world this shop was not in. The gap
     * between them is then the new shop undercutting the old ones, which is
     * not a price movement at all.
     *
     * A product's only shop is excluded by the second half: its first reading
     * against the product's own history is an ordinary drop.
     */
    public function joinsAProductAlreadyWatchedElsewhere(): bool
    {
        if ($this->hasEarlierReadingAtItsOwnShop()) {
            return false;
        }

        return self::query()
            ->where('id', '<', $this->id)
            ->whereNot('shop_id', $this->shop_id)
            ->whereHas('shop', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop
                ->where('product_id', $this->shop->product_id))
            ->eligible()
            ->exists();
    }

    /**
     * Readings from before Jev confirmed the shop's pack size do not count:
     * the shop only joined the comparison then, as a new shop does. The same
     * for a shop that competes on the second size it states, from when that
     * size was stored or confirmed — but not for a shop that competes on its
     * own pack columns.
     */
    private function hasEarlierReadingAtItsOwnShop(): bool
    {
        $joinedAt = $this->shop?->confirmed_pack_quantity === null ? null : $this->shop->pack_checked_at;
        $comparesIn = $this->shop?->product?->best_value_pack_unit;
        $altSince = $this->shop !== null && $comparesIn !== null
            && $this->shop->alt_pack_unit === $comparesIn && $this->shop->pack_unit !== $comparesIn
            && $this->shop->alt_pack_confirmed !== false
            ? $this->shop->alt_pack_since
            : null;

        if ($altSince !== null && ($joinedAt === null || $altSince->greaterThan($joinedAt))) {
            $joinedAt = $altSince;
        }

        return self::query()
            ->where('shop_id', $this->shop_id)
            ->where('id', '<', $this->id)
            ->when($joinedAt !== null, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('checked_at', '>', $joinedAt))
            ->eligible()
            ->exists();
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
