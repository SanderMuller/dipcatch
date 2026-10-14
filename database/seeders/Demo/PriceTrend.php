<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * How a demo product's prices moved, so the price verdicts show every label:
 * `Falling` reads "Good price", `Flat` "Normal price", `Rising` "High price",
 * and `Lowest` adds "Lowest in 90 days".
 */
enum PriceTrend
{
    case Falling;
    case Flat;
    case Rising;
    case Lowest;

    /**
     * How far the oldest price sits from today. The median lands about half
     * as far, and must clear the 5% a verdict needs despite the 2% noise.
     */
    private const float DRIFT = 0.2;

    /**
     * A product with a drop always falls, because a drop needs a price above
     * today. The others take turns by their place in the catalog, so every
     * account with ten products or more shows every label.
     */
    public static function of(DemoProduct $spec, int $index): self
    {
        if ($spec->drop !== null) {
            return self::Falling;
        }

        return match ($index % 10) {
            0 => self::Lowest,
            2, 5, 8 => self::Flat,
            3, 6 => self::Rising,
            default => self::Falling,
        };
    }

    /** "Lowest in 90 days" needs history that covers the 90 days. */
    public function historyDays(int $days): int
    {
        return $this === self::Lowest ? max($days, 90) : $days;
    }

    /**
     * A price path ending on the current price.
     *
     * @return array<int, float> index 0 = oldest day, last = today
     */
    public function walk(float $current, int $historyDays): array
    {
        $prices = [];

        for ($day = 0; $day < $historyDays; $day++) {
            $progress = $day / ($historyDays - 1);
            $drift = match ($this) {
                self::Flat => 1.0,
                self::Rising => 1.0 - self::DRIFT * (1 - $progress),
                self::Falling, self::Lowest => 1.0 + self::DRIFT * (1 - $progress),
            };
            $price = round($current * $drift * (1.0 + fake()->randomFloat(4, -0.02, 0.02)), 2);
            // No earlier day may undercut today, or today is not the lowest.
            $prices[] = $this === self::Lowest ? max($price, round($current * 1.01, 2)) : $price;
        }

        $prices[$historyDays - 1] = $current;

        return $prices;
    }
}
