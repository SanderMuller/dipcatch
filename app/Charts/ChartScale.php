<?php declare(strict_types=1);

namespace App\Charts;

/**
 * Where the value axis of a price chart starts.
 *
 * Not at zero: a price moves by cents, and a zero base flattens every drop into
 * one straight line. Not at the lowest price either: the line then runs along
 * the bottom edge, and the lowest point, often the one an alert fired on, sits
 * on the axis. The start leaves a margin below the lowest price and lands on a
 * round step, so the tick labels read €1.60, €1.70 rather than €1.7162.
 */
final class ChartScale
{
    /** The share of the price range left free below the lowest price. */
    private const float MARGIN = 0.15;

    /** Ticks the axis aims for between its start and the highest price. */
    private const int TICKS = 4;

    /**
     * The tick values, from the start up to the first one at or above the
     * highest price. Passed to the chart as given, so Flux does not pick a
     * step of its own that the start is not a multiple of.
     *
     * @param  list<float>  $values
     * @param  int  $decimals  The decimals the labels print: no step is finer, so no two labels read alike.
     * @return list<float>
     */
    public static function ticks(array $values, int $decimals = 2): array
    {
        if ($values === []) {
            return [];
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $floor = $min - ($range > 0 ? $range * self::MARGIN : max($min * 0.05, 0.01));
        $step = max(self::niceStep(($max - $floor) / self::TICKS), 10 ** -$decimals);
        $tick = max(0.0, round(floor($floor / $step) * $step, 6));

        $ticks = [$tick];

        while ($tick < $max) {
            $tick = round($tick + $step, 6);
            $ticks[] = $tick;
        }

        return $ticks;
    }

    /**
     * The nearest of 1, 2 and 5 times a power of ten, at or above `$raw`. Not
     * 2.5: a 0.025 step prints as €0.98, €1.00, €1.03 at two decimals.
     */
    private static function niceStep(float $raw): float
    {
        $magnitude = 10 ** floor(log10($raw));

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($raw <= $multiple * $magnitude) {
                return $multiple * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
