<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\ProductCheapestHistory;
use App\Support\PackSize;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which stretch of a product's history is still comparable with today, and on
 * what size.
 *
 * Split out of {@see Reference} because it answers a different question: that
 * class takes a median, this one decides which samples share a scale with each
 * other at all.
 */
final class ReferenceEpoch
{
    /**
     * The segments since the product last changed what it measures.
     *
     * A comparison unit defines an epoch: numbers from either side of a change
     * share no scale, and a time-weighted median across the boundary mixes
     * them. A pack size corrected on the *same* shop starts one too — Foodello
     * reporting 55 g for a box of twelve made a month of readings read twelve
     * times dear, and leaving those in the median would fire the next ordinary
     * price move against them.
     *
     * A segment that simply has no size does not break the epoch. Products go
     * briefly sizeless whenever a shop falls out of stock, and ending the epoch
     * there would collapse the reference to its fallback every time.
     *
     * @param  list<ProductCheapestHistory>  $segments  oldest first
     * @return list<ProductCheapestHistory>
     */
    public static function currentEpoch(array $segments, string $unit): array
    {
        $start = 0;
        $previous = null;

        foreach ($segments as $index => $segment) {
            $size = $segment->packSize();

            if ($size === null) {
                continue;
            }

            // The two boundaries start in different places, and a shared `+ 1`
            // would break one of them. A segment in another unit belongs to the
            // *old* epoch, so the new one starts after it. A corrected size is
            // the first reading of the new epoch, so it starts on it.
            if ($size->unit !== $unit) {
                $start = $index + 1;
            } elseif (self::isSizeCorrection($previous, $segment)) {
                $start = $index;
            }

            $previous = $segment;
        }

        return array_slice($segments, $start);
    }

    /**
     * The same shop, the same money, a different pack: that is someone fixing a
     * pack size, not a price moving.
     */
    public static function isSizeCorrection(?ProductCheapestHistory $previous, ProductCheapestHistory $segment): bool
    {
        if ($previous === null || $previous->best_value_shop_id === null) {
            return false;
        }

        return $previous->best_value_shop_id === $segment->best_value_shop_id
            && ! $previous->packSize()?->isSameSizeAs($segment->packSize())
            && self::packPriceOf($previous) === self::packPriceOf($segment);
    }

    /**
     * @param  list<ProductCheapestHistory>  $contributing
     */
    public static function epochStart(array $contributing, CarbonImmutable $windowStart): CarbonImmutable
    {
        $first = $contributing[0] ?? null;
        $started = $first?->started_at;

        if (! $started instanceof CarbonInterface) {
            return $windowStart;
        }

        $startedAt = CarbonImmutable::parse($started->toDateTimeString());

        return $startedAt->isAfter($windowStart) ? $startedAt : $windowStart;
    }

    /**
     * The one pack size every contributing segment carried, or null when they
     * differ or any of them carried none. Only a shared size makes the money
     * difference between two readings a saving anybody made.
     *
     * @param  list<ProductCheapestHistory>  $contributing
     */
    public static function sharedSize(array $contributing): ?PackSize
    {
        $first = null;

        foreach ($contributing as $segment) {
            $size = $segment->packSize();

            if ($size === null) {
                return null;
            }

            $first ??= $size;

            if (! $first->isSameSizeAs($size)) {
                return null;
            }
        }

        return $first;
    }

    public static function packPriceOf(ProductCheapestHistory $segment): ?string
    {
        $price = $segment->best_value_price ?? $segment->cheapest_price;

        return $price === null ? null : (string) $price;
    }
}
