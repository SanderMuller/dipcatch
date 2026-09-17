<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\ShopSnapshot;

/**
 * What one price check read, in the shape `CheckShopPrice::persist()` needs.
 *
 * A check either read a product or it did not, so the snapshot is the
 * discriminant: `persist()` reaches through it with `?->` and gets the same
 * nulls a failure used to state field by field. The image and the adapter key
 * sit beside it because neither is the snapshot's to give — they belong to
 * the source that produced it.
 */
final readonly class CheckOutcome
{
    private function __construct(
        public ScrapeStatus $status,
        public ?ShopSnapshot $snapshot,
        public ?string $adapterKey,
        public ?string $imageUrl,
        public ?string $error,
    ) {}

    /**
     * `$adapterKey` is nullable because an `ExtractionResult` can succeed
     * without naming the adapter that won. `persist()` leaves the stored key
     * alone in that case rather than blanking it.
     */
    public static function success(ShopSnapshot $snapshot, ?string $adapterKey, ?string $imageUrl): self
    {
        return new self(ScrapeStatus::Ok, $snapshot, $adapterKey, $imageUrl, error: null);
    }

    public static function failure(ScrapeStatus $status, ?string $error): self
    {
        return new self($status, snapshot: null, adapterKey: null, imageUrl: null, error: $error);
    }
}
