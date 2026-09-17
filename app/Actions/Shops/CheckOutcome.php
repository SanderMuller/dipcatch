<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\ShopSnapshot;
use App\Support\PackSize;

/**
 * What one price check read, in the shape `CheckShopPrice::persist()` needs.
 *
 * A check either read a product or it did not, so the snapshot is the
 * discriminant: `persist()` reaches through it with `?->` and gets the same
 * nulls a failure used to state field by field. The image is the one value a
 * snapshot cannot supply on its own — a fetched page resolves a relative URL
 * against the page it came from, a dataset has no page to resolve against.
 *
 * `status` is what the check produced. `persist()` can still downgrade an
 * `Ok` to `CurrencyMismatch` after comparing against the locked row, and it
 * keeps reading the snapshot when it does: the reported price belongs on the
 * failed check, so the owner can see what the shop actually quoted.
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

    /**
     * No adapter carries a structured size and a title at once, so the size
     * is resolved the same way for every source: the field when it is
     * authoritative, the title otherwise.
     */
    public function packSize(): ?PackSize
    {
        if ($this->snapshot === null) {
            return null;
        }

        return PackSize::resolve(
            $this->snapshot->packSize,
            $this->snapshot->packSizeAuthoritative,
            $this->snapshot->title,
        );
    }
}
