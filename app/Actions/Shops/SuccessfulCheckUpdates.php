<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ShopHealth;
use App\Models\Shop;
use App\PriceAdapters\ShopSnapshot;
use App\Support\AltPackSize;
use App\Support\MovedShopUrl;
use App\Support\PackSize;
use App\Support\PromotedPackSize;
use Carbon\CarbonInterface;

/**
 * The shop columns a successful price check writes.
 */
final readonly class SuccessfulCheckUpdates
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Shop $shop, CheckOutcome $outcome, ShopSnapshot $snapshot, ResolvedBundlePricing $pricing, ?string $claim, ?string $storedCurrency, CarbonInterface $now): array
    {
        $updates = [];

        // A reader without the concept leaves the stored claim alone.
        if ($snapshot->claimAuthoritative) {
            $updates['claimed_regular_price'] = $claim;
        }

        $updates += $pricing->shopUpdates() + [
            // Unknown stays unknown: coercing it to true is what
            // reported a sold-out product as available.
            'current_in_stock' => $snapshot->inStock,
            // Re-read on every successful check, so a shop that opens
            // to the public, or starts quoting ex-VAT, joins or leaves
            // the comparison on its own.
            'consumer_price_issue' => $snapshot->consumerPriceIssue,
            'consumer_price_note' => $snapshot->consumerPriceNote,
            // An empty currency is no signal at all — keep the last known one
            // rather than blanking the column.
            'currency' => $storedCurrency ?? $shop->currency,
            'last_success_at' => $now,
            'last_error' => null,
            'consecutive_failures' => 0,
            'consecutive_5xx_failures' => 0,
            'health' => $shop->health === ShopHealth::Failing
                ? ShopHealth::Ok
                : $shop->health,
        ];

        if ($outcome->adapterKey !== null) {
            $updates['adapter_key'] = $outcome->adapterKey;
        }

        // Store where the page moved for good, so the next check
        // asks once instead of following the redirect every time.
        $updates += MovedShopUrl::updates($shop, $outcome->movedFrom, $outcome->movedTo);

        // Keep the last known image when an extraction returns none —
        // an empty picker is worse than a slightly stale thumbnail.
        if ($outcome->imageUrl !== null) {
            $updates['image_url'] = $outcome->imageUrl;
        }

        // The GTIN follows the opposite rule: an adapter that reads
        // GTIN fields and finds none is authoritative, and the stored
        // value is cleared — a mismatch warning must not outlive the
        // data it was raised on. A source with no GTIN concept (the
        // AH API, the dataset) leaves the value alone.
        if ($snapshot->gtin !== null || $snapshot->gtinAuthoritative) {
            $updates['gtin'] = $snapshot->gtin;
        }

        // An authoritative size is written verbatim — an empty or
        // unparseable one clears the columns, because a stale unit
        // price is worse than none. A title fallback only ever fills:
        // a flaky title must not wipe a known size (spec Section 4).
        $packSize = PackSize::resolve(
            $snapshot->packSize,
            $snapshot->packSizeAuthoritative,
            $snapshot->title,
            $snapshot->packSizeIsWholePack,
            countsItems: $shop->product?->category?->department()->countsItems() === true,
        );
        $promoted = PromotedPackSize::for($shop, $packSize, $snapshot);
        $packSize ??= $promoted;

        if ($snapshot->packSizeAuthoritative || $packSize !== null) {
            $updates['pack_quantity'] = $packSize?->quantity;
            $updates['pack_unit'] = $packSize?->unit;
        }

        $updates += AltPackSize::updates($shop, $packSize, $snapshot, promoted: $promoted !== null);

        return $updates + $pricing->promotionUpdates;
    }
}
