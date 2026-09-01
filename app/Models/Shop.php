<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Support\Favicon;
use App\Support\ImageUrl;
use App\Support\PackSize;
use App\Support\UrlNormalizer;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ShopHealth $health
 * @property string|null $pack_quantity
 * @property string|null $pack_unit
 */
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_price' => 'decimal:2',
            'current_price' => 'decimal:2',
            'pack_quantity' => 'decimal:2',
            'initial_checked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'current_in_stock' => 'boolean',
            'active' => 'boolean',
            'health' => ShopHealth::class,
            'last_status' => ScrapeStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $shop): void {
            if (! is_string($shop->url) || $shop->url === '') {
                return;
            }

            $normalized = UrlNormalizer::normalize($shop->url);
            $shop->url_hash = UrlNormalizer::hash($normalized);
            $shop->host = UrlNormalizer::normalizeHost(parse_url($normalized, PHP_URL_HOST) ?: '');
        });
    }

    /**
     * Apply a manually-edited URL: re-normalize, recompute url_hash + host,
     * and reset the failure counters / health so a user-initiated URL fix
     * unblocks an offer that had previously gone dead. Returns true when
     * the URL actually changed.
     */
    public function updateUrl(string $normalized): bool
    {
        $newHash = UrlNormalizer::hash($normalized);

        if ($newHash === $this->url_hash) {
            return false;
        }

        $this->forceFill([
            'url' => $normalized,
            'url_hash' => $newHash,
            'host' => UrlNormalizer::normalizeHost(parse_url($normalized, PHP_URL_HOST) ?: ''),
            'consecutive_failures' => 0,
            'consecutive_5xx_failures' => 0,
            'last_status' => ScrapeStatus::Pending,
            'last_error' => null,
            'health' => ShopHealth::Ok,
            'active' => true,
            // Drop URL-blind hints so the next probe re-runs the full chain.
            // Stale selectors / variant keys would otherwise match the first
            // element on the new page (e.g. zooplus user-selector pinned to
            // `[data-zta="reducedPriceAmount"]` always picks the first
            // variant regardless of ?activeVariant). Host adapters that key
            // on URL (ZooplusAdapter, JsonLdAdapter) then pick up.
            'price_selector' => null,
            'title_selector' => null,
            'image_selector' => null,
            'variant_key' => null,
            'image_url' => null,
            // A different product means a different pack: a stale size would
            // price the new offer wrongly until the next successful check.
            'pack_quantity' => null,
            'pack_unit' => null,
        ])->save();

        return true;
    }

    /**
     * Normalized unit price (per kg / l / piece) for the current pack price,
     * or null when either the price or the pack size is unknown. A plain
     * method, not an accessor — UI callers invoke it explicitly.
     */
    public function unitPrice(): ?string
    {
        $price = $this->current_price;

        if (! is_string($price) && ! is_numeric($price)) {
            return null;
        }

        return $this->packSize()?->unitPriceFor((string) $price);
    }

    /**
     * `/kg`, `/l` or `/stuk` — null whenever {@see unitPrice()} is null, so
     * no orphan label renders next to a missing price.
     */
    public function unitPriceLabel(): ?string
    {
        return $this->unitPrice() === null ? null : $this->packSize()?->label();
    }

    public function faviconUrl(): string
    {
        return Favicon::url($this->host);
    }

    private function packSize(): ?PackSize
    {
        if ($this->pack_quantity === null || $this->pack_unit === null) {
            return null;
        }

        return PackSize::of((float) $this->pack_quantity, $this->pack_unit);
    }

    public function safeImageUrl(): ?string
    {
        return ImageUrl::safe($this->image_url);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<PriceCheck, $this>
     */
    public function priceChecks(): HasMany
    {
        return $this->hasMany(PriceCheck::class);
    }

    /**
     * @return HasMany<PriceDropEvent, $this>
     */
    public function triggeredDropEvents(): HasMany
    {
        return $this->hasMany(PriceDropEvent::class, 'triggered_by_shop_id');
    }
}
