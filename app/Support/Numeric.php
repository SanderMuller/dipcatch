<?php declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Numeric
{
    /**
     * Assert that a string is numeric and return it for use with `bcmath`,
     * which requires `numeric-string` rather than plain `string` under
     * PHPStan's strict types.
     *
     * @return numeric-string
     */
    public static function str(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Expected a numeric string, got: '{$value}'.");
        }

        return $value;
    }

    /**
     * A stored decimal without the zeros its column pads it with: `7.0000`
     * reads as `7`, and `7.3200` as `7.32`.
     */
    public static function trimmed(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * A drop percentage with one decimal at most: `12.3` reads as `12.3%`
     * and `12.0` as `12%`.
     */
    public static function percent(mixed $value): ?string
    {
        return is_numeric($value)
            ? self::trimmed(number_format((float) $value, 1, '.', '')) . '%'
            : null;
    }
}
