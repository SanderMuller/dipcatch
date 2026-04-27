<?php declare(strict_types=1);

namespace App\Services\Drops;

final class TierDefaults
{
    /**
     * Adaptive default thresholds per price tier (currency-blind for v1).
     *
     * @return array{pct: float, abs: float}
     */
    public static function for(string|float|int $price): array
    {
        $value = is_string($price) ? (float) $price : (float) $price;

        return match (true) {
            $value < 25.0 => ['pct' => 15.0, 'abs' => 3.0],
            $value < 100.0 => ['pct' => 10.0, 'abs' => 7.0],
            $value < 500.0 => ['pct' => 8.0,  'abs' => 25.0],
            $value < 2000.0 => ['pct' => 5.0,  'abs' => 50.0],
            default => ['pct' => 3.0,  'abs' => 100.0],
        };
    }
}
