<?php declare(strict_types=1);

namespace App\Models;

use App\PriceAdapters\BundleOffer;
use Carbon\CarbonImmutable;
use Database\Factories\ProductCheapestHistoryFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One segment of a product's cheapest-offer history.
 *
 * @property int $id
 * @property string $product_id
 * @property string|null $cheapest_shop_id
 * @property string|null $cheapest_price
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $triggering_price_check_id
 * @property string|null $single_item_price
 * @property int|null $bundle_quantity
 * @property string|null $bundle_total_price
 * @property-read Product $product
 */
#[WithoutTimestamps]
#[Unguarded]
class ProductCheapestHistory extends Model
{
    /** @use HasFactory<ProductCheapestHistoryFactory> */
    use HasFactory;

    // Larastan needs $table as a property (not #[Table] attribute) to introspect
    // model properties via the migration schema. Don't switch back to #[Table]
    // unless we also update larastan to support attribute-table model discovery.
    protected $table = 'product_cheapest_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheapest_price' => 'decimal:2',
            'single_item_price' => 'decimal:2',
            'bundle_quantity' => 'integer',
            'bundle_total_price' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function singleItemPrice(): ?string
    {
        $price = $this->single_item_price ?? $this->cheapest_price;

        return $price === null ? null : (string) $price;
    }

    public function bundleOffer(): ?BundleOffer
    {
        if ($this->bundle_quantity === null || $this->bundle_total_price === null) {
            return null;
        }

        try {
            $offer = new BundleOffer((int) $this->bundle_quantity, (string) $this->bundle_total_price);
        } catch (InvalidArgumentException) {
            return null;
        }

        $singleItemPrice = $this->singleItemPrice();

        return $singleItemPrice !== null && $offer->isCheaperThan($singleItemPrice) ? $offer : null;
    }

    /**
     * Segments in the order they were written.
     *
     * `started_at` is a whole-second timestamp, so two segments recorded in
     * the same second tie. Ordering on it alone leaves the winner to the
     * database — SQLite and Postgres disagree — so `id` breaks the tie.
     *
     * @param EloquentQueryBuilder<$this> $query
     */
    #[Scope]
    protected function inOrder(EloquentQueryBuilder $query): void
    {
        $query->oldest('started_at')->orderBy('id');
    }

    /**
     * Segments that overlap the window, not merely those that started inside
     * it. A price that has not changed for a year is one open segment that
     * started before any window — matching on `started_at` alone would render
     * an empty chart for the products that are working best.
     *
     * A null window means the account may read everything, so the scope
     * no-ops and callers need no conditional of their own.
     *
     * @param EloquentQueryBuilder<$this> $query
     */
    #[Scope]
    protected function overlapping(EloquentQueryBuilder $query, ?DateTimeInterface $windowStart): void
    {
        if ($windowStart === null) {
            return;
        }

        $query->where(function (EloquentQueryBuilder $inner) use ($windowStart): void {
            $inner->where('started_at', '>=', $windowStart)
                ->orWhereNull('ended_at')
                ->orWhere('ended_at', '>=', $windowStart);
        });
    }

    /**
     * Segments newest first. See {@see inOrder} for why `id` is here.
     *
     * @param EloquentQueryBuilder<$this> $query
     */
    #[Scope]
    protected function newestFirst(EloquentQueryBuilder $query): void
    {
        $query->latest('started_at')->orderByDesc('id');
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
    public function cheapestShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'cheapest_shop_id');
    }

    /**
     * @return BelongsTo<PriceCheck, $this>
     */
    public function triggeringPriceCheck(): BelongsTo
    {
        return $this->belongsTo(PriceCheck::class, 'triggering_price_check_id');
    }
}
