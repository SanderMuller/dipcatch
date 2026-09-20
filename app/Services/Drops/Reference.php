<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Support\Numeric;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class Reference
{
    private const int MEDIAN_MIN_SAMPLES = 7;

    private const int WINDOW_DAYS = 30;

    private const int BC_SCALE = 4;

    /**
     * Compute a reference price for drop detection.
     *
     * Reads `product_cheapest_history` segments overlapping the 30-day window
     * and treats each segment as one sample weighted by how long the product
     * held that price. The MEDIAN_30D vs INITIAL gate uses the count of
     * successful `price_checks` inside the window (not segment count), so a
     * stable low-volatility product with one long segment and dozens of
     * checks still graduates past the initial-price baseline — preserving
     * the "every price_check inside the window is a sample" semantics from
     * the pre-refactor design while letting segments carry the actual
     * weighted-median value.
     *
     * Segments an offer recorded before it was repointed are left out — see
     * {@see ProductCheapestHistory::recordedOnTheCurrentPage()}. A product whose
     * every sample is excluded has no reference at all, so no drop fires
     * until the new pages have built one.
     */
    public function compute(Product $product): ?ReferenceValue
    {
        $windowStart = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);
        $now = CarbonImmutable::now();

        /** @var EloquentCollection<int, ProductCheapestHistory> $segments */
        $segments = $product->cheapestHistory()
            ->whereNotNull('cheapest_price')
            ->recordedOnTheCurrentPage()
            ->where(function (EloquentBuilder $q) use ($windowStart): void {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', $windowStart);
            })
            ->inOrder()
            ->get();

        $unit = $product->comparablePacks()->unit();

        $contributing = $unit === null
            ? $segments->values()->all()
            : ReferenceEpoch::currentEpoch($segments->values()->all(), $unit);

        // Only the segments that actually answer in this basis. A sizeless
        // segment stays in the epoch — products go briefly sizeless whenever a
        // shop falls out of stock — but it contributes no sample and must not
        // date the admission window either.
        $usable = self::usable($contributing, $unit);
        $weighted = self::samples($usable, $unit, $windowStart, $now);

        // Admission counts the checks this basis actually saw, from the first
        // segment that could answer in it. Counting the whole window instead
        // lets seven readings taken before the product had a comparable size
        // graduate a median standing on one usable segment — which is every
        // product that existed before this change.
        $countFrom = $unit === null
            ? $windowStart
            : ReferenceEpoch::epochStart($usable, $windowStart);

        $checkCount = $this->successfulCheckCountInWindow($product, $countFrom);

        $sharedSize = ReferenceEpoch::sharedSize($usable);

        if ($checkCount >= self::MEDIAN_MIN_SAMPLES && $weighted !== []) {
            return new ReferenceValue(
                value: $this->weightedMedian($weighted, 'price'),
                kind: ReferenceValue::KIND_MEDIAN_30D,
                sampleSize: $checkCount,
                unit: $unit,
                packValue: $sharedSize === null && $unit !== null ? null : $this->weightedMedian($weighted, 'pack'),
                packQuantity: $sharedSize?->quantity,
                packUnit: $sharedSize?->unit,
            );
        }

        $first = $this->earliestSegment($product, $unit);

        $initial = $first === null
            ? null
            : ($unit === null
                ? ($first->cheapest_price === null ? null : (string) $first->cheapest_price)
                : $first->unitPrice());

        // A basis with no convertible earliest segment has no reference at all,
        // and nothing fires until one exists. Handing back a pack price to be
        // measured against a price per kilo is a category error, not a drop.
        if ($initial === null) {
            return null;
        }

        return new ReferenceValue(
            value: $initial,
            kind: ReferenceValue::KIND_INITIAL,
            sampleSize: $checkCount,
            unit: $unit,
            packValue: $unit === null ? $initial : ReferenceEpoch::packPriceOf($first),
            packQuantity: $first->packSize()?->quantity,
            packUnit: $first->packSize()?->unit,
        );
    }

    /**
     * One weighted sample per segment that can answer in this basis. A segment
     * carrying no size contributes nothing on the unit basis, and says so by
     * being absent rather than by guessing.
     *
     * @param  list<ProductCheapestHistory>  $contributing
     * @return list<array{price: string, pack: string, weightSeconds: int}>
     */
    private static function samples(array $contributing, ?string $unit, CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        $samples = [];

        foreach ($contributing as $segment) {
            $seconds = self::weightSeconds($segment, $windowStart, $now);
            $price = $unit === null
                ? ($segment->cheapest_price === null ? null : (string) $segment->cheapest_price)
                : $segment->unitPrice();
            $pack = ReferenceEpoch::packPriceOf($segment);

            if ($seconds === null || $price === null || $pack === null) {
                continue;
            }

            $samples[] = ['price' => $price, 'pack' => $pack, 'weightSeconds' => $seconds];
        }

        return $samples;
    }

    /** How long this segment held, clipped to the window. */
    private static function weightSeconds(ProductCheapestHistory $segment, CarbonImmutable $windowStart, CarbonImmutable $now): ?int
    {
        $started = $segment->started_at;
        $ended = $segment->ended_at ?? $now;

        if (! $started instanceof CarbonInterface || ! $ended instanceof CarbonInterface) {
            return null;
        }

        $clippedStart = $started->isAfter($windowStart) ? $started : $windowStart;
        $clippedEnd = $ended->isBefore($now) ? $ended : $now;

        $seconds = max(0, $clippedEnd->getTimestamp() - $clippedStart->getTimestamp());

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * The contributing segments that can answer in this basis.
     *
     * @param  list<ProductCheapestHistory>  $contributing
     * @return list<ProductCheapestHistory>
     */
    private static function usable(array $contributing, ?string $unit): array
    {
        if ($unit === null) {
            return $contributing;
        }

        return array_values(array_filter(
            $contributing,
            static fn (ProductCheapestHistory $segment): bool => $segment->packSize()?->unit === $unit,
        ));
    }

    /**
     * The segment the fallback reference reads.
     *
     * On the pack basis that is the product's earliest segment, as it always
     * has been — deliberately not window-limited, because a price that has not
     * moved in two months is still the price to measure against. On the unit
     * basis it is the earliest segment of the *current* epoch: a segment from
     * before a unit change or a pack-size correction shares no scale with today.
     */
    private function earliestSegment(Product $product, ?string $unit): ?ProductCheapestHistory
    {
        /** @var EloquentCollection<int, ProductCheapestHistory> $all */
        $all = $product->cheapestHistory()
            ->whereNotNull('cheapest_price')
            ->recordedOnTheCurrentPage()
            ->inOrder()
            ->get();

        if ($unit === null) {
            return $all->first();
        }

        foreach (ReferenceEpoch::currentEpoch($all->values()->all(), $unit) as $segment) {
            // The unit check is belt and braces beside the epoch slice: this
            // reference is unbounded by the window, and a price per litre
            // measured against a price per kilo is not a drop, it is a category
            // error.
            if ($segment->packSize()?->unit === $unit && $segment->unitPrice() !== null) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Count successful price_checks for any of this product's offers within
     * the 30-day window. Drives the MEDIAN_30D gate.
     */
    private function successfulCheckCountInWindow(Product $product, CarbonImmutable $windowStart): int
    {
        return PriceCheck::query()
            ->whereIn('shop_id', $product->shops()->select('id'))
            ->where('status', ScrapeStatus::Ok->value)
            ->where('checked_at', '>=', $windowStart)
            ->count();
    }

    /**
     * Time-weighted median: sort samples by price, find the value where the
     * cumulative weight crosses half of the total weight.
     *
     * @param  list<array{price: string, pack: string, weightSeconds: int}>  $samples
     * @param  'price'|'pack'  $key
     */
    private function weightedMedian(array $samples, string $key): string
    {
        usort($samples, static fn (array $a, array $b): int => bccomp(
            Numeric::str($a[$key]),
            Numeric::str($b[$key]),
            self::BC_SCALE,
        ));

        $total = 0;
        foreach ($samples as $sample) {
            $total += $sample['weightSeconds'];
        }

        $half = $total / 2;
        $cumulative = 0;

        foreach ($samples as $sample) {
            $cumulative += $sample['weightSeconds'];

            if ($cumulative >= $half) {
                return $sample[$key];
            }
        }

        // Caller guarantees non-empty (we exit at the top of compute when
        // sample count < MEDIAN_MIN_SAMPLES = 7), but assert to satisfy the
        // type checker — `array_key_last` returns null on empty arrays.
        $lastIndex = array_key_last($samples);
        assert($lastIndex !== null);

        return $samples[$lastIndex][$key];
    }
}
