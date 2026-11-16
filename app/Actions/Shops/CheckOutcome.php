<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ScrapeStatus;
use App\PriceAdapters\ShopSnapshot;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;

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
        /** False when the failure is DipCatch's own, so the shop's failure count stays. */
        public bool $countsAgainstShop = true,
    ) {}

    /**
     * `$adapterKey` is nullable because an `ExtractionResult` can succeed
     * without naming the adapter that won. `persist()` leaves the stored key
     * alone in that case rather than blanking it.
     */
    public static function success(ShopSnapshot $snapshot, ?string $adapterKey, ?string $imageUrl, ?string $movedTo = null, ?string $movedFrom = null): self
    {
        return new self(ScrapeStatus::Ok, $snapshot, $adapterKey, $imageUrl, error: null, movedTo: $movedTo, movedFrom: $movedFrom);
    }

    public static function failure(ScrapeStatus $status, ?string $error): self
    {
        return new self($status, snapshot: null, adapterKey: null, imageUrl: null, error: $error);
    }

    /**
     * A host that stayed throttled through every attempt. When the throttle
     * is DipCatch's own, the check is recorded but says nothing about the
     * shop: probes from any account draw on the same throttle, so counting
     * it would let one account walk another account's shop to dead.
     */
    public static function rateLimited(RateLimitedByHost $e): self
    {
        return new self(
            $e->status(),
            snapshot: null,
            adapterKey: null,
            imageUrl: null,
            error: $e->getMessage(),
            countsAgainstShop: $e->source !== RateLimitedByHost::SOURCE_LOCAL,
        );
    }
}
