<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Enums\ScrapeStatus;
use App\Models\Product;
use App\Support\Numeric;

final class Reference
{
    private const int MEDIAN_MIN_SAMPLES = 7;

    private const int WINDOW_DAYS = 30;

    private const int BC_SCALE = 4;

    public function compute(Product $product): ?ReferenceValue
    {
        /** @var array<int, string> $prices */
        $prices = $product->priceChecks()
            ->where('status', ScrapeStatus::Ok)
            ->where('checked_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotNull('price')
            ->pluck('price')
            ->all();

        $count = count($prices);

        if ($count >= self::MEDIAN_MIN_SAMPLES) {
            return new ReferenceValue(
                value: $this->median($prices),
                kind: ReferenceValue::KIND_MEDIAN_30D,
                sampleSize: $count,
            );
        }

        $initial = $product->initial_price;

        if ($initial === null) {
            return null;
        }

        return new ReferenceValue(
            value: (string) $initial,
            kind: ReferenceValue::KIND_INITIAL,
            sampleSize: $count,
        );
    }

    /**
     * @param  array<int, string>  $prices
     */
    private function median(array $prices): string
    {
        usort($prices, static fn (string $a, string $b): int => bccomp(Numeric::str($a), Numeric::str($b), self::BC_SCALE));

        $count = count($prices);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $prices[$mid];
        }

        return bcdiv(
            bcadd(Numeric::str($prices[$mid - 1]), Numeric::str($prices[$mid]), self::BC_SCALE),
            '2',
            self::BC_SCALE,
        );
    }
}
