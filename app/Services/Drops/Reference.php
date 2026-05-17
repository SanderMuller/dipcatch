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
     */
    public function compute(Product $product): ?ReferenceValue
    {
        $windowStart = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);
        $now = CarbonImmutable::now();

        /** @var EloquentCollection<int, ProductCheapestHistory> $segments */
        $segments = $product->cheapestHistory()
            ->whereNotNull('cheapest_price')
            ->where(function (EloquentBuilder $q) use ($windowStart): void {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', $windowStart);
            })
            ->oldest('started_at')
            ->get();

        /** @var list<array{price: string, weightSeconds: int}> $weighted */
        $weighted = [];

        foreach ($segments as $segment) {
            $started = $segment->started_at;
            $ended = $segment->ended_at ?? $now;

            if (! $started instanceof CarbonInterface || ! $ended instanceof CarbonInterface) {
                continue;
            }

            $clippedStart = $started->isAfter($windowStart) ? $started : $windowStart;
            $clippedEnd = $ended->isBefore($now) ? $ended : $now;

            $seconds = max(0, $clippedEnd->getTimestamp() - $clippedStart->getTimestamp());

            if ($seconds <= 0) {
                continue;
            }

            $price = $segment->cheapest_price;
            if ($price === null) {
                continue;
            }

            $weighted[] = ['price' => (string) $price, 'weightSeconds' => $seconds];
        }

        $checkCount = $this->successfulCheckCountInWindow($product, $windowStart);

        if ($checkCount >= self::MEDIAN_MIN_SAMPLES && $weighted !== []) {
            return new ReferenceValue(
                value: $this->weightedMedian($weighted),
                kind: ReferenceValue::KIND_MEDIAN_30D,
                sampleSize: $checkCount,
            );
        }

        $initial = $this->initialPrice($product);

        if ($initial === null) {
            return null;
        }

        return new ReferenceValue(
            value: $initial,
            kind: ReferenceValue::KIND_INITIAL,
            sampleSize: $checkCount,
        );
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
     * @param  list<array{price: string, weightSeconds: int}>  $samples
     */
    private function weightedMedian(array $samples): string
    {
        usort($samples, static fn (array $a, array $b): int => bccomp(
            Numeric::str($a['price']),
            Numeric::str($b['price']),
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
                return $sample['price'];
            }
        }

        // Caller guarantees non-empty (we exit at the top of compute when
        // sample count < MEDIAN_MIN_SAMPLES = 7), but assert to satisfy the
        // type checker — `array_key_last` returns null on empty arrays.
        $lastIndex = array_key_last($samples);
        assert($lastIndex !== null);

        return $samples[$lastIndex]['price'];
    }

    /**
     * Earliest cheapest_price observed for this product. Functional analogue
     * of the old `initial_price` baseline.
     */
    private function initialPrice(Product $product): ?string
    {
        $first = $product->cheapestHistory()
            ->whereNotNull('cheapest_price')
            ->oldest('started_at')
            ->first();

        if ($first === null || $first->cheapest_price === null) {
            return null;
        }

        return (string) $first->cheapest_price;
    }
}
