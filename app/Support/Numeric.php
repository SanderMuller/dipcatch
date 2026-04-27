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
}
