<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\BundleOffer;
use Carbon\CarbonImmutable;
use Database\Factories\PriceCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

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
class PriceCheck extends Model
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
}
