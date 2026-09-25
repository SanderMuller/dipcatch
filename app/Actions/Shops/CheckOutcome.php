<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\ShopSnapshot;
use Carbon\CarbonImmutable;

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
        /** Where the page moved permanently, for `persist()` to consider storing. */
        public ?string $movedTo = null,
        /** The URL that was fetched, so a move is not stored over a URL changed since. */
        public ?string $movedFrom = null,
        /** When the page was read, for a reading shared from another row; now when null. */
        public ?CarbonImmutable $readAt = null,
    ) {}

    /**
     * `$adapterKey` is nullable because an `ExtractionResult` can succeed
     * without naming the adapter that won. `persist()` leaves the stored key
     * alone in that case rather than blanking it.
     */
    public static function success(ShopSnapshot $snapshot, ?string $adapterKey, ?string $imageUrl, ?string $movedTo = null, ?string $movedFrom = null, ?CarbonImmutable $readAt = null): self
    {
        return new self(ScrapeStatus::Ok, $snapshot, $adapterKey, $imageUrl, error: null, movedTo: $movedTo, movedFrom: $movedFrom, readAt: $readAt);
    }

    /** A reading another row of the same page took, dated when it was taken. */
    public static function shared(PageReading $reading): self
    {
        return self::success($reading->snapshot, $reading->adapterKey, $reading->imageUrl, readAt: $reading->readAt);
    }

    public static function failure(ScrapeStatus $status, ?string $error): self
    {
        return new self($status, snapshot: null, adapterKey: null, imageUrl: null, error: $error);
    }
}
