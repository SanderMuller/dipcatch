<?php declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the dates Dutch shops state on their promotions.
 *
 * A date without a time is a retail day in Europe/Amsterdam: a bonus ending
 * "2026-09-06" runs until that evening, not until 02:00 the next morning,
 * which is what reading it as UTC would mean. A value that already carries a
 * time or an offset is left as the instant it states.
 */
final readonly class DutchDate
{
    public const string ZONE = 'Europe/Amsterdam';

    public static function startOfDay(mixed $value): ?CarbonImmutable
    {
        return self::parse($value, endOfDay: false);
    }

    public static function endOfDay(mixed $value): ?CarbonImmutable
    {
        return self::parse($value, endOfDay: true);
    }

    private static function parse(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($value, self::ZONE);
        } catch (Throwable) {
            return null;
        }

        if (! self::isDateOnly($value)) {
            return $parsed;
        }

        return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
    }

    private static function isDateOnly(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }
}
